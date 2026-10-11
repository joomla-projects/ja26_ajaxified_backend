/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const STRING_KEYS = Object.freeze(['name', 'contact', 'email', 'extrainfo', 'metakey', 'metakey_prefix', 'version_note']);
const INTEGER_KEYS = Object.freeze(['purchase_type', 'track_impressions', 'track_clicks', 'own_prefix']);
const PAYLOAD_KEYS = Object.freeze([...STRING_KEYS, ...INTEGER_KEYS]);
const MAXIMUM_ID = 4294967295;
const MAXIMUM_LENGTHS = Object.freeze({
  name: 255, contact: 255, email: 255, extrainfo: 65535, metakey: 65535, metakey_prefix: 400, version_note: 255,
});
const INTEGER_VALUES = Object.freeze({
  purchase_type: Object.freeze([-1, 0, 1, 2, 3, 4, 5]),
  track_impressions: Object.freeze([-1, 0, 1]),
  track_clicks: Object.freeze([-1, 0, 1]),
  own_prefix: Object.freeze([0, 1]),
});

const isPlainObject = (value) => value !== null && typeof value === 'object'
  && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const normalizeCanonicalId = (value) => {
  const candidate = typeof value === 'number' && Number.isSafeInteger(value) ? String(value) : value;
  if (typeof candidate !== 'string' || !/^[1-9][0-9]{0,9}$/.test(candidate) || Number(candidate) > MAXIMUM_ID) {
    throw new TypeError('The Banner Client Autosave target is invalid.');
  }
  return candidate;
};
const normalizeAutosaveTarget = (value) => (value === null
  || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value))
  ? value : normalizeCanonicalId(value));
const parseInteger = (value, key) => {
  if (typeof value !== 'string' || !/^-?(?:0|[1-9][0-9]*)$/.test(value)) {
    throw new TypeError('The Banner Client Autosave field is invalid.');
  }
  const integer = Number(value);
  if (!Number.isSafeInteger(integer) || !INTEGER_VALUES[key].includes(integer)) {
    throw new TypeError('The Banner Client Autosave field is invalid.');
  }
  return integer;
};
const validatePayload = (payload) => {
  if (!isPlainObject(payload) || Object.keys(payload).length !== PAYLOAD_KEYS.length
    || !PAYLOAD_KEYS.every((key) => Object.hasOwn(payload, key))
    || !STRING_KEYS.every((key) => typeof payload[key] === 'string' && Array.from(payload[key]).length <= MAXIMUM_LENGTHS[key])
    || !INTEGER_KEYS.every((key) => Number.isInteger(payload[key]) && INTEGER_VALUES[key].includes(payload[key]))) {
    throw new TypeError('The Banner Client Autosave recovery payload is invalid.');
  }
  return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, payload[key]]));
};
const reportCallbackError = (error) => {
  if (typeof globalThis.reportError === 'function') globalThis.reportError(error);
  else queueMicrotask(() => { throw error; });
};

export default class ClientAutosaveAdapter {
  constructor({ descriptor, form, fields, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!isPlainObject(descriptor) || typeof descriptor.context !== 'string' || !descriptor.context
      || !Number.isInteger(descriptor.payloadSchemaVersion) || descriptor.payloadSchemaVersion <= 0
      || !form || !isPlainObject(fields) || !PAYLOAD_KEYS.every((key) => fields[key]) || typeof eventFactory !== 'function') {
      throw new TypeError('The Banner Client Autosave adapter configuration is invalid.');
    }
    this.descriptor = Object.freeze({
      context: descriptor.context, targetId: normalizeAutosaveTarget(descriptor.targetId), payloadSchemaVersion: descriptor.payloadSchemaVersion,
    });
    this.form = form;
    this.fields = { ...fields };
    this.eventFactory = eventFactory;
    this.baseline = null;
    this.callback = null;
    this.subscribed = false;
    this.destroyed = false;
    this.suppressChanges = 0;
    this.listeners = Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, () => this.handleChange()]));
  }

  getDescriptor() { return this.descriptor; }

  initializeBaseline() { this.assertCurrent(); this.baseline = this.readSnapshot(); return this; }

  subscribe(callback) {
    if (typeof callback !== 'function') throw new TypeError('The Banner Client Autosave change callback must be a function.');
    if (this.destroyed || this.subscribed) throw new Error('The Banner Client Autosave adapter cannot be subscribed.');
    if (!this.baseline) this.initializeBaseline();
    this.subscribed = true;
    this.callback = callback;
    PAYLOAD_KEYS.forEach((key) => this.controls(key).forEach((control) => {
      control.addEventListener('input', this.listeners[key]);
      control.addEventListener('change', this.listeners[key]);
    }));
    let active = true;
    return () => { if (active) { active = false; this.unsubscribe(); } };
  }

  capture() { this.assertCurrent(); const snapshot = this.readSnapshot(); this.baseline = snapshot; return { ...snapshot }; }

  apply(payload) {
    if (this.destroyed) throw new Error('The Banner Client Autosave adapter has been destroyed.');
    const next = validatePayload(payload);
    this.assertCurrent();
    const previous = this.readSnapshot();
    this.suppressChanges += 1;
    try {
      this.applySnapshot(next);
      const applied = this.readSnapshot();
      if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) throw new Error('The Banner Client Autosave recovery payload could not be applied.');
      this.baseline = applied;
    } catch (error) {
      try { this.applySnapshot(previous); this.baseline = previous; } catch (rollbackError) { Object.defineProperty(error, 'rollbackError', { value: rollbackError }); }
      throw error;
    } finally { this.suppressChanges -= 1; }
  }

  destroy() { if (this.destroyed) return; this.destroyed = true; this.unsubscribe(); this.baseline = null; this.form = null; this.fields = null; this.listeners = null; }

  controls(key) { return key === 'own_prefix' ? this.fields[key] : [this.fields[key]]; }

  readValue(key) {
    if (key === 'own_prefix') {
      const selected = this.fields[key].find((control) => control.checked);
      if (!selected) throw new TypeError('The Banner Client Autosave field is invalid.');
      return parseInteger(selected.value, key);
    }
    const value = this.fields[key].value;
    if (STRING_KEYS.includes(key)) {
      if (typeof value !== 'string' || Array.from(value).length > MAXIMUM_LENGTHS[key]) throw new TypeError('The Banner Client Autosave field is invalid.');
      return value;
    }
    return parseInteger(value, key);
  }

  readSnapshot() { return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, this.readValue(key)])); }

  applySnapshot(snapshot) {
    PAYLOAD_KEYS.forEach((key) => {
      if (key === 'own_prefix') this.fields[key].forEach((control) => { control.checked = control.value === String(snapshot[key]); });
      else this.fields[key].value = String(snapshot[key]);
      this.controls(key).forEach((control) => {
        control.dispatchEvent(this.eventFactory('input'));
        control.dispatchEvent(this.eventFactory('change'));
      });
    });
  }

  handleChange() {
    if (this.destroyed || this.suppressChanges || !this.callback || !this.isCurrent()) return;
    const snapshot = this.readSnapshot();
    if (PAYLOAD_KEYS.every((key) => snapshot[key] === this.baseline?.[key])) return;
    this.baseline = snapshot;
    try { this.callback(); } catch (error) { reportCallbackError(error); }
  }

  isCurrent() {
    return !this.destroyed && this.form?.isConnected && this.fields
      && PAYLOAD_KEYS.every((key) => this.controls(key).length > 0
        && this.controls(key).every((control) => control?.isConnected && this.form.contains(control)));
  }

  assertCurrent() { if (!this.isCurrent()) throw new Error('The Banner Client Autosave form is no longer current.'); }

  unsubscribe() {
    if (!this.subscribed) return;
    PAYLOAD_KEYS.forEach((key) => this.controls(key).forEach((control) => {
      control.removeEventListener('input', this.listeners[key]);
      control.removeEventListener('change', this.listeners[key]);
    }));
    this.subscribed = false;
    this.callback = null;
  }
}

export { INTEGER_KEYS, MAXIMUM_LENGTHS, PAYLOAD_KEYS, STRING_KEYS, normalizeCanonicalId, validatePayload };
