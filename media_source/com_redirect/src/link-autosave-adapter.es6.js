/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const PAYLOAD_KEYS = Object.freeze(['old_url', 'new_url', 'comment']);
const MAXIMUM_ID = 4294967295;
const MAXIMUM_LENGTHS = Object.freeze({
  old_url: 2048,
  new_url: 2048,
  comment: 255,
});

const isPlainObject = (value) => value !== null
  && typeof value === 'object'
  && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;

const normalizeCanonicalId = (value) => {
  const candidate = typeof value === 'number' && Number.isSafeInteger(value)
    ? String(value)
    : value;

  if (typeof candidate !== 'string'
    || !/^[1-9][0-9]{0,9}$/.test(candidate)
    || Number(candidate) > MAXIMUM_ID) {
    throw new TypeError('The Redirect Autosave target is invalid.');
  }

  return candidate;
};

const normalizeAutosaveTarget = (value) => {
  if (value === null || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value))) {
    return value;
  }

  return normalizeCanonicalId(value);
};

const validatePayload = (payload) => {
  if (!isPlainObject(payload)) {
    throw new TypeError('The Redirect Autosave recovery payload is invalid.');
  }

  const keys = Object.keys(payload).sort();
  const expected = [...PAYLOAD_KEYS].sort();

  if (keys.length !== expected.length
    || !keys.every((key, index) => key === expected[index])
    || !PAYLOAD_KEYS.every((key) => typeof payload[key] === 'string')
    || !PAYLOAD_KEYS.every((key) => Array.from(payload[key]).length <= MAXIMUM_LENGTHS[key])) {
    throw new TypeError('The Redirect Autosave recovery payload is invalid.');
  }

  return {
    old_url: payload.old_url,
    new_url: payload.new_url,
    comment: payload.comment,
  };
};

const reportCallbackError = (error) => {
  if (typeof globalThis.reportError === 'function') {
    globalThis.reportError(error);

    return;
  }

  queueMicrotask(() => {
    throw error;
  });
};

/**
 * Component-owned adapter between a Redirect edit form and Autosave.
 */
export default class LinkAutosaveAdapter {
  constructor({
    descriptor,
    form,
    fields,
    eventFactory = (type) => new Event(type, { bubbles: true }),
  }) {
    if (!isPlainObject(descriptor)
      || typeof descriptor.context !== 'string'
      || descriptor.context.length === 0
      || !Number.isInteger(descriptor.payloadSchemaVersion)
      || descriptor.payloadSchemaVersion <= 0
      || !form
      || !isPlainObject(fields)
      || !PAYLOAD_KEYS.every((key) => fields[key])
      || typeof eventFactory !== 'function') {
      throw new TypeError('The Redirect Autosave adapter configuration is invalid.');
    }

    this.descriptor = Object.freeze({
      context: descriptor.context,
      targetId: normalizeAutosaveTarget(descriptor.targetId),
      payloadSchemaVersion: descriptor.payloadSchemaVersion,
    });
    this.form = form;
    this.fields = { ...fields };
    this.eventFactory = eventFactory;
    this.baseline = null;
    this.onMeaningfulChange = null;
    this.subscribed = false;
    this.destroyed = false;
    this.suppressChanges = 0;
    this.listeners = Object.fromEntries(PAYLOAD_KEYS.map((key) => [
      key,
      () => this.handleFieldChange(key),
    ]));
  }

  getDescriptor() {
    return this.descriptor;
  }

  initializeBaseline() {
    this.assertCurrent();
    this.baseline = this.readSnapshot();

    return this;
  }

  subscribe(onMeaningfulChange) {
    if (typeof onMeaningfulChange !== 'function') {
      throw new TypeError('The Redirect Autosave change callback must be a function.');
    }

    if (this.destroyed || this.subscribed) {
      throw new Error('The Redirect Autosave adapter cannot be subscribed.');
    }

    if (!this.baseline) {
      this.initializeBaseline();
    }

    this.subscribed = true;
    this.onMeaningfulChange = onMeaningfulChange;
    PAYLOAD_KEYS.forEach((key) => {
      this.fields[key].addEventListener('input', this.listeners[key]);
      this.fields[key].addEventListener('change', this.listeners[key]);
    });

    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.unsubscribe();
    };
  }

  capture() {
    this.assertCurrent();
    const snapshot = this.readSnapshot();
    this.baseline = snapshot;

    return { ...snapshot };
  }

  apply(payload) {
    if (this.destroyed) {
      throw new Error('The Redirect Autosave adapter has been destroyed.');
    }

    const next = validatePayload(payload);
    this.assertCurrent();
    const previous = this.readSnapshot();
    this.suppressChanges += 1;

    try {
      this.applySnapshot(next);

      const applied = this.readSnapshot();

      if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) {
        throw new Error('The Redirect Autosave recovery payload could not be applied.');
      }

      this.baseline = applied;
    } catch (error) {
      try {
        this.applySnapshot(previous);
        this.baseline = previous;
      } catch (rollbackError) {
        Object.defineProperty(error, 'rollbackError', {
          configurable: true,
          value: rollbackError,
        });
      }

      throw error;
    } finally {
      this.suppressChanges -= 1;
    }
  }

  destroy() {
    if (this.destroyed) {
      return;
    }

    this.destroyed = true;
    this.unsubscribe();
    this.baseline = null;
    this.form = null;
    this.fields = null;
    this.listeners = null;
  }

  handleFieldChange(field) {
    if (this.destroyed || this.suppressChanges > 0 || !this.onMeaningfulChange) {
      return;
    }

    if (!this.isCurrent()) {
      return;
    }

    const value = this.readValue(field);

    if (this.baseline?.[field] === value) {
      return;
    }

    this.baseline = { ...this.baseline, [field]: value };

    try {
      this.onMeaningfulChange();
    } catch (error) {
      reportCallbackError(error);
    }
  }

  readSnapshot() {
    return {
      old_url: this.readValue('old_url'),
      new_url: this.readValue('new_url'),
      comment: this.readValue('comment'),
    };
  }

  readValue(field) {
    if (!PAYLOAD_KEYS.includes(field) || typeof this.fields[field].value !== 'string') {
      throw new TypeError('The Redirect Autosave field is invalid.');
    }

    return this.fields[field].value;
  }

  applySnapshot(snapshot) {
    PAYLOAD_KEYS.forEach((key) => {
      this.fields[key].value = snapshot[key];
      this.fields[key].dispatchEvent(this.eventFactory('input'));
      this.fields[key].dispatchEvent(this.eventFactory('change'));
    });
  }

  isCurrent() {
    return !this.destroyed
      && this.form?.isConnected
      && this.fields
      && PAYLOAD_KEYS.every((key) => this.fields[key]?.isConnected)
      && PAYLOAD_KEYS.every((key) => this.form.contains(this.fields[key]));
  }

  assertCurrent() {
    if (!this.isCurrent()) {
      throw new Error('The Redirect Autosave form is no longer current.');
    }
  }

  unsubscribe() {
    if (!this.subscribed) {
      return;
    }

    PAYLOAD_KEYS.forEach((key) => {
      this.fields[key].removeEventListener('input', this.listeners[key]);
      this.fields[key].removeEventListener('change', this.listeners[key]);
    });
    this.subscribed = false;
    this.onMeaningfulChange = null;
  }
}

export {
  MAXIMUM_LENGTHS,
  PAYLOAD_KEYS,
  normalizeCanonicalId,
  validatePayload,
};
