/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Keep only opaque creation references in this history entry, never draft content.
 * A fresh navigation starts clean; reload/back navigation resumes this entry's work.
 */
export function creationRecovery(binding, history = globalThis.history,
  navigation = globalThis.performance?.getEntriesByType?.('navigation')[0]?.type) {
  if (!binding.creating) return null;
  const validId = (id) => typeof id === 'string' && /^[a-f0-9]{32}$/.test(id);
  const stateObject = (state) => state === null || (typeof state === 'object'
    && !Array.isArray(state) && Object.getPrototypeOf(state) === Object.prototype);
  try {
    if (!history || !stateObject(history.state) || !validId(binding.draftId)
      || typeof binding.context !== 'string' || !['navigate', 'reload', 'back_forward', 'prerender'].includes(navigation)) return false;
    const previous = history.state?.joomlaAutosaveCreation;
    const resume = ['reload', 'back_forward'].includes(navigation) && previous?.context === binding.context;
    let record = {
      context: binding.context,
      draftId: resume && validId(previous.draftId) ? previous.draftId : null,
      acknowledgedId: resume && validId(previous.acknowledgedId) ? previous.acknowledgedId : null,
    };
    const sources = {
      ...(record.draftId ? { creation_id: record.draftId } : {}),
      ...(record.acknowledgedId ? { acknowledged_id: record.acknowledgedId } : {}),
    };
    if (!resume && previous) {
      const next = { ...history.state };
      delete next.joomlaAutosaveCreation;
      history.replaceState(next, '');
    }
    const remember = (acknowledged) => {
      try {
        if (!stateObject(history.state)) return false;
        const next = { ...record, draftId: binding.draftId,
          acknowledgedId: acknowledged ? binding.draftId : record.acknowledgedId };
        const saved = history.state?.joomlaAutosaveCreation;
        if (saved?.context !== next.context || saved.draftId !== next.draftId
          || saved.acknowledgedId !== next.acknowledgedId) {
          history.replaceState({ ...history.state, joomlaAutosaveCreation: next }, '');
        }
        record = next;
        return true;
      } catch (error) {
        return false;
      }
    };
    return { sources, attempted: () => remember(false), acknowledged: () => remember(true) };
  } catch (error) {
    return false;
  }
}

const sameProjection = (left, right) => Object.keys(left).length === Object.keys(right).length
  && Object.entries(left).every(([key, value]) => Object.hasOwn(right, key)
    && (Array.isArray(value) && Array.isArray(right[key])
      ? value.length === right[key].length && value.every((selected, index) => selected === right[key][index])
      : right[key] === value));

/**
 * One native form instance, one outstanding preserve request, no canonical-save transport.
 */
export default class DraftRuntime {
  constructor({
    binding, capture, baseline = null, onState = () => {}, fetcher = (url, options) => globalThis.fetch(url, options),
    csrfToken = () => Joomla.getOptions('csrf.token'), debounce = 600, timeout = 15000, interval = binding.interval ?? 30000,
  }) {
    this.binding = binding;
    this.capture = capture;
    this.baseline = baseline;
    this.onState = onState;
    this.fetcher = fetcher;
    this.csrfToken = csrfToken;
    this.debounce = debounce;
    this.interval = interval;
    this.deadlineTimer = null;
    this.dirtySince = null;
    this.acknowledged = null;
    this.initialProjection = null;
    this.retired = false;
    this.submitting = false;
    this.timeout = timeout;
    this.revision = 0;
    this.pending = false;
    this.failed = null;
    this.inFlight = null;
    this.timer = null;
    this.requests = new Set();
    this.destroyed = false;
    this.epoch = 0;
    this.blocked = false;
    this.lastPreserved = null;
    this.submission = null;
    this.lastAttempt = null;
    this.state = 'clean';
  }

  status(state, code = null) {
    if (this.destroyed) return;
    this.protectionState = state;
    this.code = code;
    this.state = state;
    this.onState({ state, code, lastPreserved: this.lastPreserved });
  }

  changed() {
    if (this.destroyed) return;
    this.revision += 1;
    this.pending = true;
    if (this.dirtySince === null) this.dirtySince = Date.now();
    if (this.blocked) return;
    this.status('dirty');
    this.schedule();
  }

  stopTimers() {
    clearTimeout(this.timer);
    clearTimeout(this.deadlineTimer);
    this.timer = null;
    this.deadlineTimer = null;
  }

  schedule() {
    if (this.destroyed || this.retired || this.submitting
      || this.blocked || this.inFlight || !this.pending) return;
    const due = () => { this.stopTimers(); this.flush(); };
    clearTimeout(this.timer);
    this.timer = setTimeout(due, this.debounce);
    if (this.deadlineTimer === null) {
      this.deadlineTimer = setTimeout(due, Math.max(0, this.interval - (Date.now() - this.dirtySince)));
    }
  }

  reconcileControls() {
    try {
      const current = this.capture();
      const previous = this.acknowledged ?? this.baseline?.() ?? this.initialProjection;
      if (!previous || !sameProjection(current, previous)) this.changed();
    } catch (error) {
      this.blocked = true;
      this.status('unavailable', 'unsupported_controls');
    }
  }

  /**
   * Freeze a submitted sequence without waiting for storage or preventing native submission.
   */
  submitted() {
    if (this.destroyed) return null;
    let payload;
    try {
      payload = this.capture();
    } catch (error) {
      this.blocked = true;
      this.status('unavailable', 'unsupported_controls');
      return null;
    }
    this.revision += 1;
    this.pending = true;
    const revision = this.revision;
    this.submission = { client_revision: revision, payload };
    this.flush(false, payload);
    this.submitting = true;
    this.stopTimers();
    return revision;
  }

  async request(operation, data) {
    if (this.destroyed) throw new DOMException('Disposed form', 'AbortError');
    const controller = new AbortController();
    this.requests.add(controller);
    let timer;
    const deadline = new Promise((resolve, reject) => {
      timer = setTimeout(() => {
        controller.abort();
        reject(Object.assign(new Error('Draft response timeout'), { code: 'transport_failure' }));
      }, this.timeout);
    });
    try {
      const response = await Promise.race([
        (async () => {
          const url = new URL(this.binding.endpoint, globalThis.location?.href);
          url.searchParams.set('task', 'draft.' + operation);
          const result = await this.fetcher(url.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken() },
            body: JSON.stringify(data),
            signal: controller.signal,
          });
          const body = await result.json();
          if (!result.ok || body.success !== true) {
            throw Object.assign(new Error('Draft request failed'), {
              code: body.error?.code || (result.status === 401 ? 'authentication_required' : 'service_unavailable'),
            });
          }
          return body.data;
        })(),
        deadline,
      ]);
      if (this.destroyed) throw new DOMException('Disposed form', 'AbortError');
      return response;
    } catch (error) {
      if (!error.code && error.name !== 'AbortError') error.code = 'transport_failure';
      throw error;
    } finally {
      clearTimeout(timer);
      this.requests.delete(controller);
    }
  }

  flush(retry = false, captured) {
    if (this.destroyed || this.retired || this.inFlight || (this.blocked && !retry) || (!this.pending && !this.failed)) {
      return this.inFlight || Promise.resolve(false);
    }
    this.stopTimers();
    let snapshot;
    try {
      snapshot = retry && this.failed
        ? this.failed
        : {
            draft_id: this.binding.draftId,
            schema_version: this.binding.version,
            client_revision: this.revision,
            payload: captured === undefined ? this.capture() : captured,
          };
      if (snapshot.client_revision === this.revision) { this.pending = false; this.dirtySince = null; }
    } catch (error) {
      this.blocked = true;
      this.status('unavailable', 'unsupported_controls');
      return Promise.resolve(false);
    }
    if (!retry && this.acknowledged && sameProjection(snapshot.payload, this.acknowledged)) {
      this.status('preserved');
      return Promise.resolve(false);
    }
    // An untouched native form is not unfinished work, for either new or existing items.
    if (!retry && !this.failed && !this.lastPreserved) {
      const initial = this.baseline?.() ?? this.initialProjection;
      if (initial && sameProjection(snapshot.payload, initial)) {
        this.status('clean');
        return Promise.resolve(false);
      }
    }
    // Native editors may notify change again while synchronising a submitted form.
    // Identical values must stay at the sequence that native success can retire.
    if (!retry && this.submission && snapshot.client_revision > this.submission.client_revision
      && (!this.lastAttempt || this.lastAttempt.revision <= this.submission.client_revision)
      && sameProjection(snapshot.payload, this.submission.payload)) {
      if (this.lastAttempt?.revision === this.submission.client_revision) {
        this.status(this.lastAttempt.acknowledged ? 'preserved' : 'clean');
        return Promise.resolve(false);
      }
      // Submission can be queued behind an older preserve. Keep its recovery copy
      // if native Save fails, without manufacturing a later unsaved revision.
      snapshot.client_revision = this.submission.client_revision;
    }
    const attempt = { revision: snapshot.client_revision, acknowledged: false };
    this.lastAttempt = attempt;
    const epoch = this.epoch;
    this.status('preserving');
    this.inFlight = (async () => {
      try {
        let result;
        try {
          result = await this.request('preserve', snapshot);
        } catch (error) {
          if (!['draft_expired', 'draft_not_found'].includes(error.code) || this.lastPreserved
            || this.submitting || this.retired) throw error;
          const resumed = await this.request('resume', { draft_id: snapshot.draft_id, schema_version: snapshot.schema_version });
          if (this.destroyed || epoch !== this.epoch
            || this.submitting || this.retired) {
            this.pending = true;
            return false;
          }
          if (resumed?.draft_id !== snapshot.draft_id || resumed.schema_version !== snapshot.schema_version
            || resumed.resumed !== true) {
            throw Object.assign(new Error('Invalid draft resumption'), { code: 'service_unavailable' });
          }
          // Admission is not persistence. Recapture edits made during negotiation.
          snapshot = { ...snapshot, client_revision: this.revision, payload: this.capture() };
          attempt.revision = snapshot.client_revision;
          this.pending = false;
          this.dirtySince = null;
          result = await this.request('preserve', snapshot);
        }
        if (this.destroyed || epoch !== this.epoch) return false;
        if (!result || typeof result.updated_at !== 'string' || !Number.isFinite(Date.parse(result.updated_at))
          || typeof result.conflict !== 'boolean') {
          throw Object.assign(new Error('Invalid draft acknowledgement'), { code: 'service_unavailable' });
        }
        if (result.client_revision !== snapshot.client_revision) {
          throw Object.assign(new Error('Unexpected draft acknowledgement'), { code: 'revision_conflict' });
        }
        attempt.acknowledged = true;
        this.failed = null;
        this.lastPreserved = result.updated_at;
        this.acknowledged = snapshot.payload;
        this.blocked = result.conflict === true;
        const state = result.conflict
          ? 'conflict'
          : (this.pending ? 'dirty' : 'preserved');
        this.status(state);
        return true;
      } catch (error) {
        if (this.destroyed || epoch !== this.epoch) return false;
        // An older in-flight snapshot can retire while a later dirty sequence still needs writing.
        if (error.code === 'draft_retired' && this.pending && this.revision > snapshot.client_revision) {
          this.failed = null;
          this.blocked = false;
          return false;
        }
        this.failed = snapshot;
        this.blocked = true;
        const retryable = ['transport_failure', 'service_unavailable', 'internal_error'].includes(error.code);
        this.status(error.code === 'base_revision_conflict' ? 'conflict' : (retryable ? 'offline' : 'unavailable'), error.code);
        return false;
      } finally {
        this.inFlight = null;
        this.stopTimers();
        this.schedule();
      }
    })();
    return this.inFlight;
  }

  async candidates(cursor = null) {
    const epoch = this.epoch;
    const revision = this.revision;
    if (!this.pending && !this.inFlight && !this.failed && !this.blocked && !this.lastPreserved) {
      this.status('detecting');
    }
    try {
      const result = await this.request('detect', { draft_id: this.binding.draftId,
        ...(this.binding.creating ? this.binding.creationSources : {}),
        ...(cursor === null ? {} : { cursor }) });
      if (this.destroyed || epoch !== this.epoch) throw new DOMException('Disposed detection', 'AbortError');
      if (!result || !Array.isArray(result.candidates) || typeof result.conflict !== 'boolean'
        || (result.next != null && (typeof result.next !== 'string' || !/^[a-f0-9]{32}$/.test(result.next)))
        || result.candidates.some((candidate) => !candidate || typeof candidate.draft_id !== 'string' || !/^[a-f0-9]{32}$/.test(candidate.draft_id)
          || typeof candidate.updated_at !== 'string' || !Number.isFinite(Date.parse(candidate.updated_at)))) {
        throw Object.assign(new Error('Invalid draft detection response'), { code: 'service_unavailable' });
      }
      if (result.conflict && revision === this.revision && !this.inFlight && !this.failed && !this.blocked) {
        this.blocked = true;
        this.status('conflict');
      } else if (this.state === 'detecting') {
        this.status('clean');
      }
      return result;
    } catch (error) {
      if (!this.destroyed && epoch === this.epoch && this.state === 'detecting') {
        const retryable = ['transport_failure', 'service_unavailable', 'internal_error'].includes(error.code);
        this.status(retryable ? 'offline' : 'unavailable', error.code);
      }
      throw error;
    }
  }

  async read(sourceId) {
    return this.request('read', { draft_id: this.binding.draftId, source_id: sourceId });
  }

  async discard(id) {
    await this.request('discard', { draft_id: id });
    if (id === this.binding.draftId) {
      this.epoch += 1;
      this.blocked = true;
      this.pending = false;
      this.failed = null;
      this.retired = true;
      this.stopTimers();
      this.status('discarded');
    }
  }

  retry() {
    if (this.state !== 'offline') return Promise.resolve(false);
    this.blocked = false;
    return this.flush(true);
  }

  resumeControls() {
    if (this.protectionState === 'unavailable' && this.code === 'unsupported_controls' && this.failed === null) {
      this.blocked = false;
      this.status('clean');
    }
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.epoch += 1;
    this.stopTimers();
    this.requests.forEach((controller) => controller.abort());
    this.requests.clear();
    this.submission = null;
    this.lastAttempt = null;
  }
}
