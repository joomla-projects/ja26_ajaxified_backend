/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const STRING_KEYS = Object.freeze(['position', 'target', 'title', 'description', 'url', 'note', 'requiredvalue']);
const INTEGER_KEYS = Object.freeze(['type', 'interactive_type', 'required']);
const PAYLOAD_KEYS = Object.freeze([...STRING_KEYS, ...INTEGER_KEYS]);
const CONTROL_KEYS = Object.freeze(['position', 'target', 'title', 'type', 'url', 'interactive_type', 'note', 'requiredvalue']);
const MAXIMUM_ID = 4294967295;
const MAXIMUM_LENGTHS = Object.freeze({ position: 255, target: 255, title: 255, description: 65535, url: 255, note: 255, requiredvalue: 65535 });
const ENUMS = Object.freeze({ position: Object.freeze(['bottom', 'center', 'left', 'right', 'top']), type: Object.freeze([0, 1, 2]), interactive_type: Object.freeze([1, 2, 3, 4, 5, 6]), required: Object.freeze([0, 1]) });

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const normalizeCanonicalId = (value) => {
  const candidate = typeof value === 'number' && Number.isSafeInteger(value) ? String(value) : value;
  if (typeof candidate !== 'string' || !/^[1-9][0-9]{0,9}$/.test(candidate) || Number(candidate) > MAXIMUM_ID) throw new TypeError('The Guided Tour Step Autosave target is invalid.');
  return candidate;
};
const normalizeAutosaveTarget = (value) => (value === null || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value)) ? value : normalizeCanonicalId(value));
const parseInteger = (value, key) => {
  if (typeof value !== 'string' || !/^(0|[1-9][0-9]*)$/.test(value)) throw new TypeError('The Guided Tour Step Autosave field is invalid.');
  const parsed = Number(value); if (!ENUMS[key].includes(parsed)) throw new TypeError('The Guided Tour Step Autosave field is invalid.'); return parsed;
};
const validatePayload = (payload) => {
  if (!isPlainObject(payload)) throw new TypeError('The Guided Tour Step Autosave recovery payload is invalid.');
  const keys = Object.keys(payload).sort(); const expected = [...PAYLOAD_KEYS].sort();
  if (keys.length !== expected.length || !keys.every((key, index) => key === expected[index])
    || !STRING_KEYS.every((key) => typeof payload[key] === 'string' && Array.from(payload[key]).length <= MAXIMUM_LENGTHS[key])
    || !ENUMS.position.includes(payload.position)
    || !INTEGER_KEYS.every((key) => Number.isInteger(payload[key]) && ENUMS[key].includes(payload[key]))) throw new TypeError('The Guided Tour Step Autosave recovery payload is invalid.');
  return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, payload[key]]));
};
const reportCallbackError = (error) => { if (typeof globalThis.reportError === 'function') { globalThis.reportError(error); return; } queueMicrotask(() => { throw error; }); };

export default class StepAutosaveAdapter {
  constructor({ descriptor, form, fields, editor, getCurrentEditor, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!isPlainObject(descriptor) || typeof descriptor.context !== 'string' || !Number.isInteger(descriptor.payloadSchemaVersion)
      || descriptor.payloadSchemaVersion <= 0 || !form || !isPlainObject(fields) || !PAYLOAD_KEYS.every((key) => fields[key])
      || !editor || typeof editor.getValue !== 'function' || typeof editor.setValue !== 'function' || typeof editor.subscribeChange !== 'function'
      || typeof getCurrentEditor !== 'function' || typeof eventFactory !== 'function') throw new TypeError('The Guided Tour Step Autosave adapter configuration is invalid.');
    this.descriptor = Object.freeze({ context: descriptor.context, targetId: normalizeAutosaveTarget(descriptor.targetId), payloadSchemaVersion: descriptor.payloadSchemaVersion });
    this.form = form; this.fields = { ...fields }; this.editor = editor; this.editorId = fields.description.id; this.getCurrentEditor = getCurrentEditor; this.eventFactory = eventFactory;
    this.baseline = null; this.callback = null; this.subscribed = false; this.destroyed = false; this.suppressChanges = 0; this.unsubscribeEditor = null;
    this.listeners = Object.fromEntries([...CONTROL_KEYS, 'required'].map((key) => [key, () => this.handleChange()]));
  }

  getDescriptor() { return this.descriptor; }
  controls(key) { return key === 'required' ? this.fields.required : [this.fields[key]]; }
  initializeBaseline() { this.assertCurrent(); this.baseline = this.readSnapshot(); return this; }
  subscribe(callback) {
    if (typeof callback !== 'function') throw new TypeError('The Guided Tour Step Autosave change callback must be a function.');
    if (this.destroyed || this.subscribed) throw new Error('The Guided Tour Step Autosave adapter cannot be subscribed.');
    if (!this.baseline) this.initializeBaseline(); this.callback = callback; this.subscribed = true;
    [...CONTROL_KEYS, 'required'].forEach((key) => this.controls(key).forEach((control) => ['input', 'change'].forEach((type) => control.addEventListener(type, this.listeners[key]))));
    this.unsubscribeEditor = this.editor.subscribeChange(() => this.handleChange()); let active = true;
    return () => { if (active) { active = false; this.unsubscribe(); } };
  }

  capture() { this.assertCurrent(); const snapshot = this.readSnapshot(); this.baseline = snapshot; return { ...snapshot }; }
  apply(payload) {
    if (this.destroyed) throw new Error('The Guided Tour Step Autosave adapter has been destroyed.');
    const next = validatePayload(payload); this.assertCurrent(); const previous = this.readSnapshot(); this.suppressChanges += 1;
    try { this.applySnapshot(next); const applied = this.readSnapshot(); if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) throw new Error('The Guided Tour Step Autosave recovery payload could not be applied.'); this.baseline = applied; }
    catch (error) { try { this.applySnapshot(previous); this.baseline = previous; } catch (rollbackError) { Object.defineProperty(error, 'rollbackError', { configurable: true, value: rollbackError }); } throw error; }
    finally { this.suppressChanges -= 1; }
  }

  destroy() { if (this.destroyed) return; this.destroyed = true; this.unsubscribe(); this.baseline = null; this.form = null; this.fields = null; this.editor = null; this.listeners = null; }
  handleChange() { if (this.destroyed || this.suppressChanges || !this.callback || !this.isCurrent()) return; const snapshot = this.readSnapshot(); if (PAYLOAD_KEYS.every((key) => snapshot[key] === this.baseline?.[key])) return; this.baseline = snapshot; try { this.callback(); } catch (error) { reportCallbackError(error); } }
  readSnapshot() {
    const required = this.fields.required.find((control) => control.checked)?.value;
    return { position: this.fields.position.value, target: this.fields.target.value, title: this.fields.title.value, description: this.editor.getValue(), type: parseInteger(this.fields.type.value, 'type'), url: this.fields.url.value, interactive_type: parseInteger(this.fields.interactive_type.value, 'interactive_type'), note: this.fields.note.value, required: parseInteger(required, 'required'), requiredvalue: this.fields.requiredvalue.value };
  }

  applySnapshot(snapshot) {
    CONTROL_KEYS.forEach((key) => { const value = INTEGER_KEYS.includes(key) ? String(snapshot[key]) : snapshot[key]; this.fields[key].value = value; this.fields[key].dispatchEvent(this.eventFactory('input')); this.fields[key].dispatchEvent(this.eventFactory('change')); });
    this.editor.setValue(snapshot.description);
    this.fields.required.forEach((control) => { control.checked = control.value === String(snapshot.required); control.dispatchEvent(this.eventFactory('input')); control.dispatchEvent(this.eventFactory('change')); });
  }

  isCurrent() { return !this.destroyed && this.form?.isConnected && this.fields && PAYLOAD_KEYS.every((key) => this.controls(key).length > 0 && this.controls(key).every((control) => control?.isConnected && this.form.contains(control))) && this.getCurrentEditor(this.editorId) === this.editor; }
  assertCurrent() { if (!this.isCurrent()) throw new Error('The Guided Tour Step Autosave form or editor is no longer current.'); }
  unsubscribe() { if (!this.subscribed && !this.unsubscribeEditor) return; [...CONTROL_KEYS, 'required'].forEach((key) => this.controls(key).forEach((control) => ['input', 'change'].forEach((type) => control.removeEventListener(type, this.listeners[key])))); this.subscribed = false; this.callback = null; this.unsubscribeEditor?.(); this.unsubscribeEditor = null; }
}

export { ENUMS, MAXIMUM_LENGTHS, PAYLOAD_KEYS, normalizeCanonicalId, validatePayload };
