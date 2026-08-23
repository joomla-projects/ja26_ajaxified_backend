/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const PAYLOAD_KEYS = Object.freeze(['title', 'description']);
const MAXIMUM_ID = 4294967295;
const MAXIMUM_LENGTHS = Object.freeze({ title: 48, description: 255 });

const isPlainObject = (value) => value !== null && typeof value === 'object'
  && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;

const normalizeCanonicalId = (value) => {
  const candidate = typeof value === 'number' && Number.isSafeInteger(value) ? String(value) : value;
  if (typeof candidate !== 'string' || !/^[1-9][0-9]{0,9}$/.test(candidate)
    || Number(candidate) > MAXIMUM_ID) throw new TypeError('The Menu Autosave target is invalid.');
  return candidate;
};

const validatePayload = (payload) => {
  if (!isPlainObject(payload)) throw new TypeError('The Menu Autosave recovery payload is invalid.');
  const keys = Object.keys(payload).sort();
  const expected = [...PAYLOAD_KEYS].sort();
  if (keys.length !== expected.length || !keys.every((key, index) => key === expected[index])
    || !PAYLOAD_KEYS.every((key) => typeof payload[key] === 'string')
    || !PAYLOAD_KEYS.every((key) => Array.from(payload[key]).length <= MAXIMUM_LENGTHS[key])) {
    throw new TypeError('The Menu Autosave recovery payload is invalid.');
  }
  return { title: payload.title, description: payload.description };
};

const reportCallbackError = (error) => {
  if (typeof globalThis.reportError === 'function') { globalThis.reportError(error); return; }
  queueMicrotask(() => { throw error; });
};

export default class MenuAutosaveAdapter {
  constructor({ descriptor, form, fields, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!isPlainObject(descriptor) || typeof descriptor.context !== 'string'
      || !Number.isInteger(descriptor.payloadSchemaVersion) || descriptor.payloadSchemaVersion <= 0
      || !form || !isPlainObject(fields) || !PAYLOAD_KEYS.every((key) => fields[key])
      || typeof eventFactory !== 'function') throw new TypeError('The Menu Autosave adapter configuration is invalid.');
    this.descriptor = Object.freeze({ context: descriptor.context, targetId: normalizeCanonicalId(descriptor.targetId), payloadSchemaVersion: descriptor.payloadSchemaVersion });
    this.form = form; this.fields = { ...fields }; this.eventFactory = eventFactory;
    this.baseline = null; this.callback = null; this.subscribed = false; this.destroyed = false; this.suppressChanges = 0;
    this.listeners = Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, () => this.handleChange(key)]));
  }

  getDescriptor() { return this.descriptor; }
  initializeBaseline() { this.assertCurrent(); this.baseline = this.readSnapshot(); return this; }
  subscribe(callback) {
    if (typeof callback !== 'function') throw new TypeError('The Menu Autosave change callback must be a function.');
    if (this.destroyed || this.subscribed) throw new Error('The Menu Autosave adapter cannot be subscribed.');
    if (!this.baseline) this.initializeBaseline();
    this.callback = callback; this.subscribed = true;
    PAYLOAD_KEYS.forEach((key) => ['input', 'change'].forEach((type) => this.fields[key].addEventListener(type, this.listeners[key])));
    let active = true;
    return () => { if (active) { active = false; this.unsubscribe(); } };
  }

  capture() { this.assertCurrent(); const snapshot = this.readSnapshot(); this.baseline = snapshot; return { ...snapshot }; }
  apply(payload) {
    if (this.destroyed) throw new Error('The Menu Autosave adapter has been destroyed.');
    const next = validatePayload(payload); this.assertCurrent(); const previous = this.readSnapshot(); this.suppressChanges += 1;
    try {
      this.applySnapshot(next);
      const applied = this.readSnapshot();
      if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) throw new Error('The Menu Autosave recovery payload could not be applied.');
      this.baseline = applied;
    } catch (error) {
      try { this.applySnapshot(previous); this.baseline = previous; } catch (rollbackError) { Object.defineProperty(error, 'rollbackError', { configurable: true, value: rollbackError }); }
      throw error;
    } finally { this.suppressChanges -= 1; }
  }

  destroy() { if (this.destroyed) return; this.destroyed = true; this.unsubscribe(); this.baseline = null; this.form = null; this.fields = null; this.listeners = null; }
  handleChange(key) {
    if (this.destroyed || this.suppressChanges || !this.callback || !this.isCurrent()) return;
    const value = this.readValue(key); if (this.baseline?.[key] === value) return;
    this.baseline = { ...this.baseline, [key]: value };
    try { this.callback(); } catch (error) { reportCallbackError(error); }
  }

  readSnapshot() { return { title: this.readValue('title'), description: this.readValue('description') }; }
  readValue(key) { if (typeof this.fields[key]?.value !== 'string') throw new TypeError('The Menu Autosave field is invalid.'); return this.fields[key].value; }
  applySnapshot(snapshot) { PAYLOAD_KEYS.forEach((key) => { this.fields[key].value = snapshot[key]; this.fields[key].dispatchEvent(this.eventFactory('input')); this.fields[key].dispatchEvent(this.eventFactory('change')); }); }
  isCurrent() { return !this.destroyed && this.form?.isConnected && this.fields && PAYLOAD_KEYS.every((key) => this.fields[key]?.isConnected && this.form.contains(this.fields[key])); }
  assertCurrent() { if (!this.isCurrent()) throw new Error('The Menu Autosave form is no longer current.'); }
  unsubscribe() {
    if (!this.subscribed) return;
    PAYLOAD_KEYS.forEach((key) => ['input', 'change'].forEach((type) => this.fields[key].removeEventListener(type, this.listeners[key])));
    this.subscribed = false; this.callback = null;
  }
}

export { MAXIMUM_LENGTHS, PAYLOAD_KEYS, normalizeCanonicalId, validatePayload };
