/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import {
  AUTOSAVE_DRAFT_EVENT,
  AUTOSAVE_STATE_EVENT,
} from 'com_autosave.runtime';

const PRESENTATION_STATUSES = Object.freeze([
  'clean',
  'detecting',
  'dirty',
  'waiting-debounce',
  'initializing',
  'preserving',
  'preserved',
  'offline',
  'retry-waiting',
  'paused',
  'authentication-required',
  'conflict',
  'terminal',
  'error',
  'recovery-required',
  'recovery-applying',
  'recovery-discarding',
  'destroyed',
  'unknown',
]);

const URGENT_STATUSES = new Set([
  'authentication-required',
  'conflict',
  'terminal',
  'error',
  'recovery-required',
]);

const BUSY_STATUSES = new Set([
  'detecting',
  'initializing',
  'preserving',
  'recovery-applying',
  'recovery-discarding',
]);

const NETWORK_BLOCKED_STATUSES = new Set([
  'offline',
  'retry-waiting',
  'authentication-required',
  'conflict',
  'terminal',
  'destroyed',
]);

const ISO_TIMESTAMP = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/;

const parseTimestamp = (value) => {
  if (typeof value === 'number' && Number.isFinite(value)) {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : { date, iso: date.toISOString() };
  }

  if (typeof value !== 'string' || !ISO_TIMESTAMP.test(value)) {
    return null;
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? null : { date, iso: value };
};

/**
 * Create a safe locale-aware formatter for Autosave timestamps.
 *
 * @param {string} [locale]
 * @param {string} [timeZone]
 * @returns {Function}
 */
export const createAutosaveDateFormatter = (locale, timeZone) => {
  const options = {
    dateStyle: 'medium',
    timeStyle: 'short',
  };

  if (typeof timeZone === 'string' && timeZone.length > 0) {
    options.timeZone = timeZone;
  }

  let formatter;

  try {
    formatter = new Intl.DateTimeFormat(
      typeof locale === 'string' && locale.length > 0 ? locale : undefined,
      options,
    );
  } catch (error) {
    try {
      delete options.timeZone;
      formatter = new Intl.DateTimeFormat(undefined, options);
    } catch (fallbackError) {
      formatter = null;
    }
  }

  return (value) => {
    const parsed = parseTimestamp(value);

    if (!parsed || !formatter) {
      return null;
    }

    try {
      return {
        dateTime: parsed.iso,
        text: formatter.format(parsed.date),
      };
    } catch (error) {
      return null;
    }
  };
};

const getDialogConfirmation = (message, title) => {
  const Dialog = globalThis.customElements?.get?.('joomla-dialog');

  if (!Dialog || typeof Dialog.confirm !== 'function') {
    return Promise.resolve(false);
  }

  return Dialog.confirm(message, title);
};

const queryRequired = (mount, selector) => {
  const element = mount.querySelector(selector);

  if (!element) {
    throw new TypeError('The Autosave UI layout contract is incomplete.');
  }

  return element;
};

const setHidden = (element, hidden) => {
  element.hidden = hidden;
};

/**
 * Present one Autosave runtime using one server-rendered Joomla layout.
 */
export class AutosavePresenter {
  constructor({
    runtime,
    eventTarget,
    mount,
    locale,
    timeZone,
    confirmDiscard = getDialogConfirmation,
    formatTimestamp = createAutosaveDateFormatter(locale, timeZone),
  }) {
    if (!runtime
      || typeof runtime.restoreDetectedDraft !== 'function'
      || typeof runtime.discardDetectedDraft !== 'function'
      || typeof runtime.keepCurrent !== 'function'
      || typeof runtime.retry !== 'function'
      || !eventTarget
      || typeof eventTarget.addEventListener !== 'function'
      || typeof eventTarget.removeEventListener !== 'function'
      || !mount
      || typeof mount.hasAttribute !== 'function'
      || !mount.hasAttribute('data-joomla-autosave-ui')
      || typeof mount.querySelector !== 'function'
      || typeof confirmDiscard !== 'function'
      || typeof formatTimestamp !== 'function') {
      throw new TypeError('The Autosave presenter configuration is invalid.');
    }

    this.runtime = runtime;
    this.eventTarget = eventTarget;
    this.mount = mount;
    this.confirmDiscard = confirmDiscard;
    this.formatTimestamp = formatTimestamp;
    this.destroyed = false;
    this.generation = 0;
    this.activeAction = null;
    this.confirmationPending = false;
    this.lastUrgentKey = null;

    this.statusRegion = queryRequired(mount, '[data-autosave-status]');
    this.alertRegion = queryRequired(mount, '[data-autosave-alert]');
    this.savedTimeContainer = queryRequired(mount, '[data-autosave-saved-time-container]');
    this.savedTime = queryRequired(mount, '[data-autosave-time]');
    this.recoveryRegion = queryRequired(mount, '[data-autosave-recovery]');
    this.recoveryTimeContainer = queryRequired(
      mount,
      '[data-autosave-recovery-time-container]',
    );
    this.recoveryTime = queryRequired(mount, '[data-autosave-recovery-time]');
    this.recoveryBusy = queryRequired(mount, '[data-autosave-busy]');
    this.recoveryLocalEdits = mount.querySelector('[data-autosave-recovery-local-edits]');

    this.stateNodes = new Map(PRESENTATION_STATUSES.map((status) => [
      status,
      queryRequired(mount, `[data-autosave-state="${status}"]`),
    ]));
    this.alertNodes = new Map([
      'authentication-required',
      'conflict',
      'terminal',
      'error',
      'recovery-required',
    ].map((status) => [
      status,
      queryRequired(mount, `[data-autosave-alert-state="${status}"]`),
    ]));
    this.classificationNodes = new Map(['current', 'stale', 'unknown'].map(
      (classification) => [
        classification,
        queryRequired(mount, `[data-autosave-recovery-${classification}]`),
      ],
    ));
    this.buttons = new Map(['restore', 'keep-current', 'discard', 'retry'].map((action) => [
      action,
      queryRequired(mount, `[data-autosave-action="${action}"]`),
    ]));

    this.handleState = () => this.render(this.runtime.state);
    this.handleDraft = () => this.render(this.runtime.state);
    this.handleRestore = () => this.runRecoveryAction(
      'restore',
      () => this.runtime.restoreDetectedDraft(),
    );
    this.handleKeepCurrent = () => this.keepCurrent();
    this.handleDiscard = () => this.discard();
    this.handleRetry = () => this.retry();

    this.eventTarget.addEventListener(AUTOSAVE_STATE_EVENT, this.handleState);
    this.eventTarget.addEventListener(AUTOSAVE_DRAFT_EVENT, this.handleDraft);
    this.buttons.get('restore').addEventListener('click', this.handleRestore);
    this.buttons.get('keep-current').addEventListener('click', this.handleKeepCurrent);
    this.buttons.get('discard').addEventListener('click', this.handleDiscard);
    this.buttons.get('retry').addEventListener('click', this.handleRetry);

    this.render(this.runtime.state);
  }

  render(state) {
    if (this.destroyed || !state || typeof state !== 'object') {
      return;
    }

    const status = PRESENTATION_STATUSES.includes(state.status)
      ? state.status
      : 'unknown';
    const candidate = state.recoveryCandidate
      && typeof state.recoveryCandidate === 'object'
      ? state.recoveryCandidate
      : null;
    const recoveryBusy = this.activeAction !== null
      || status === 'recovery-applying'
      || status === 'recovery-discarding';

    this.mount.hidden = status === 'destroyed';
    this.stateNodes.forEach((node, name) => setHidden(node, name !== status));
    this.statusRegion.setAttribute('aria-busy', BUSY_STATUSES.has(status) ? 'true' : 'false');

    this.renderTimestamp(
      this.savedTimeContainer,
      this.savedTime,
      status === 'preserved' ? state.lastSuccessfulAt : null,
    );
    this.renderRecovery(candidate, state, status, recoveryBusy);
    this.renderUrgent(status, candidate);
  }

  renderRecovery(candidate, state, status, busy) {
    const hasCandidate = candidate !== null;
    const classification = hasCandidate && ['current', 'stale'].includes(candidate.classification)
      ? candidate.classification
      : 'unknown';
    const blocked = NETWORK_BLOCKED_STATUSES.has(status);
    const retryable = status === 'paused' && state.error?.retryable === true;

    this.recoveryRegion.hidden = !hasCandidate;
    this.recoveryRegion.setAttribute('aria-busy', busy ? 'true' : 'false');
    this.recoveryBusy.hidden = !busy;
    this.classificationNodes.forEach((node, name) => {
      node.hidden = !hasCandidate || name !== classification;
    });
    this.renderTimestamp(
      this.recoveryTimeContainer,
      this.recoveryTime,
      hasCandidate ? candidate.updatedAt : null,
    );

    if (this.recoveryLocalEdits) {
      this.recoveryLocalEdits.hidden = !hasCandidate || candidate.localEdits !== true;
    }

    const restore = this.buttons.get('restore');
    const keepCurrent = this.buttons.get('keep-current');
    const discard = this.buttons.get('discard');
    const retry = this.buttons.get('retry');

    restore.disabled = !hasCandidate || busy || blocked || this.confirmationPending;
    keepCurrent.disabled = !hasCandidate || busy || status === 'destroyed'
      || this.confirmationPending;
    discard.disabled = !hasCandidate || busy || blocked || this.confirmationPending;
    retry.hidden = !retryable;
    retry.disabled = !retryable || busy || this.confirmationPending;
  }

  renderTimestamp(container, time, value) {
    let formatted = null;

    try {
      formatted = value === null || value === undefined
        ? null
        : this.formatTimestamp(value);
    } catch (error) {
      formatted = null;
    }

    if (!formatted
      || typeof formatted.text !== 'string'
      || formatted.text.length === 0
      || typeof formatted.dateTime !== 'string'
      || formatted.dateTime.length === 0) {
      container.hidden = true;
      time.textContent = '';
      time.removeAttribute('datetime');

      return;
    }

    time.dateTime = formatted.dateTime;
    time.setAttribute('datetime', formatted.dateTime);
    time.textContent = formatted.text;
    container.hidden = false;
  }

  renderUrgent(status, candidate) {
    const urgentKey = URGENT_STATUSES.has(status)
      ? status
      : (candidate ? 'recovery-required' : null);

    if (urgentKey === this.lastUrgentKey) {
      return;
    }

    this.alertNodes.forEach((node, name) => setHidden(node, name !== urgentKey));
    this.lastUrgentKey = urgentKey;
  }

  async runRecoveryAction(action, callback) {
    if (this.destroyed || this.activeAction || this.confirmationPending) {
      return;
    }

    const generation = this.generation;
    const runtime = this.runtime;
    const hadRecoveryFocus = this.hasRecoveryFocus();
    this.activeAction = action;
    this.render(runtime.state);

    try {
      await callback();
    } catch (error) {
      // The runtime owns and publishes safe failure state.
    }

    if (this.destroyed || generation !== this.generation || runtime !== this.runtime) {
      return;
    }

    this.activeAction = null;
    const state = runtime.state;
    this.render(state);

    if (hadRecoveryFocus && !state.recoveryCandidate) {
      this.focusStatus();
    }
  }

  keepCurrent() {
    if (this.destroyed || this.activeAction || this.confirmationPending) {
      return;
    }

    const runtime = this.runtime;
    const hadRecoveryFocus = this.hasRecoveryFocus();
    runtime.keepCurrent();

    if (this.destroyed || runtime !== this.runtime) {
      return;
    }

    const state = runtime.state;
    this.render(state);

    if (hadRecoveryFocus && !state.recoveryCandidate) {
      this.focusStatus();
    }
  }

  async discard() {
    if (this.destroyed || this.activeAction || this.confirmationPending) {
      return;
    }

    const generation = this.generation;
    const runtime = this.runtime;
    const button = this.buttons.get('discard');
    this.confirmationPending = true;
    this.render(runtime.state);

    let confirmed = false;

    try {
      confirmed = await this.confirmDiscard(
        button.getAttribute('data-autosave-confirm-message') || '',
        button.getAttribute('data-autosave-confirm-title') || '',
      );
    } catch (error) {
      confirmed = false;
    }

    if (this.destroyed || generation !== this.generation || runtime !== this.runtime) {
      return;
    }

    this.confirmationPending = false;
    this.render(runtime.state);

    if (confirmed === true) {
      await this.runRecoveryAction('discard', () => runtime.discardDetectedDraft());
    }
  }

  retry() {
    if (this.destroyed || this.activeAction || this.confirmationPending) {
      return;
    }

    this.runtime.retry();

    if (!this.destroyed) {
      this.render(this.runtime.state);
    }
  }

  hasRecoveryFocus() {
    const activeElement = this.mount.ownerDocument?.activeElement;

    return Boolean(activeElement && this.recoveryRegion.contains(activeElement));
  }

  focusStatus() {
    if (!this.statusRegion.isConnected || typeof this.statusRegion.focus !== 'function') {
      return;
    }

    this.statusRegion.setAttribute('tabindex', '-1');

    try {
      this.statusRegion.focus({ preventScroll: true });
    } catch (error) {
      this.statusRegion.focus();
    }

    this.statusRegion.removeAttribute('tabindex');
  }

  destroy() {
    if (this.destroyed) {
      return;
    }

    this.destroyed = true;
    this.generation += 1;
    this.eventTarget.removeEventListener(AUTOSAVE_STATE_EVENT, this.handleState);
    this.eventTarget.removeEventListener(AUTOSAVE_DRAFT_EVENT, this.handleDraft);
    this.buttons.get('restore').removeEventListener('click', this.handleRestore);
    this.buttons.get('keep-current').removeEventListener('click', this.handleKeepCurrent);
    this.buttons.get('discard').removeEventListener('click', this.handleDiscard);
    this.buttons.get('retry').removeEventListener('click', this.handleRetry);
    this.mount.hidden = true;
    this.activeAction = null;
    this.runtime = null;
    this.eventTarget = null;
    this.mount = null;
  }
}

/**
 * Safely attach a generic Autosave presenter to a server-rendered layout.
 *
 * Invalid or incomplete layout overrides intentionally return null so the
 * owning component can continue running Autosave headlessly.
 *
 * @param {Object} options
 * @returns {AutosavePresenter|null}
 */
export const createAutosavePresenter = (options) => {
  try {
    return new AutosavePresenter(options);
  } catch (error) {
    return null;
  }
};

export default createAutosavePresenter;

