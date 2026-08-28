const STATIC_STRINGS = Object.freeze(['title', 'alias', 'note']);
const dangerous = new Set(['__proto__', 'prototype', 'constructor']);
const plain = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const validateSchema = (schema) => {
  if (!plain(schema) || !/^[a-f0-9]{64}$/.test(schema.fingerprint) || !Array.isArray(schema.fields) || schema.fields.length > 32) throw new TypeError('Invalid Menu Item Autosave schema.');
  const paths = new Set();
  const fields = schema.fields.map((field) => {
    if (!plain(field) || !Array.isArray(field.path) || field.path.length !== 2 || field.path[0] !== 'params' || field.path.some((part) => dangerous.has(part)) || !['string', 'boolean', 'enum', 'strings'].includes(field.kind)) throw new TypeError('Invalid Menu Item schema field.');
    const path = field.path.join('\0'); if (paths.has(path)) throw new TypeError('Duplicate Menu Item schema field.'); paths.add(path);
    return Object.freeze({ ...field, path: Object.freeze([...field.path]), values: field.values ? Object.freeze([...field.values]) : undefined });
  });
  return Object.freeze({ fingerprint: schema.fingerprint, fields: Object.freeze(fields) });
};
const dispatch = (control, makeEvent) => { control.dispatchEvent(makeEvent('input')); control.dispatchEvent(makeEvent('change')); };

export default class ItemAutosaveAdapter {
  constructor({ descriptor, form, staticFields, dynamicFields, schema, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    this.descriptor = Object.freeze({ ...descriptor }); this.form = form; this.staticFields = staticFields; this.dynamicFields = dynamicFields; this.schema = validateSchema(schema); this.eventFactory = eventFactory; this.callback = null; this.baseline = null; this.suppress = 0; this.destroyed = false; this.listener = () => this.changed();
  }
  getDescriptor() { return this.descriptor; }
  initializeBaseline() { this.baseline = this.snapshot(); return this; }
  controls() { return [...Object.values(this.staticFields), ...Object.values(this.dynamicFields).flat()]; }
  subscribe(callback) { this.callback = callback; this.controls().forEach((control) => { control.addEventListener('input', this.listener); control.addEventListener('change', this.listener); }); return () => this.unsubscribe(); }
  unsubscribe() { this.controls().forEach((control) => { control.removeEventListener('input', this.listener); control.removeEventListener('change', this.listener); }); this.callback = null; }
  read(field) { const controls = this.dynamicFields[field.path[1]]; if (field.kind === 'boolean') return controls[0].checked; if (field.kind === 'strings') return [...controls[0].selectedOptions].map((option) => option.value); if (field.kind === 'enum' && controls.length > 1) return controls.find((control) => control.checked)?.value ?? ''; return controls[0].value; }
  snapshot() { const payload = {}; STATIC_STRINGS.forEach((key) => { payload[key] = this.staticFields[key].value; }); payload.browserNav = this.staticFields.browserNav.value; payload.schemaFingerprint = this.schema.fingerprint; payload.params = {}; this.schema.fields.forEach((field) => { payload.params[field.path[1]] = this.read(field); }); return payload; }
  capture() { this.baseline = this.snapshot(); return structuredClone(this.baseline); }
  apply(payload) { this.assertPayload(payload); this.suppress += 1; try { STATIC_STRINGS.forEach((key) => { this.staticFields[key].value = payload[key]; dispatch(this.staticFields[key], this.eventFactory); }); this.staticFields.browserNav.value = payload.browserNav; dispatch(this.staticFields.browserNav, this.eventFactory); this.schema.fields.forEach((field) => { const controls = this.dynamicFields[field.path[1]]; const value = payload.params[field.path[1]]; if (field.kind === 'boolean') controls[0].checked = value; else if (field.kind === 'strings') [...controls[0].options].forEach((option) => { option.selected = value.includes(option.value); }); else if (field.kind === 'enum' && controls.length > 1) controls.forEach((control) => { control.checked = control.value === value; }); else controls[0].value = value; controls.forEach((control) => dispatch(control, this.eventFactory)); }); this.baseline = this.snapshot(); } finally { this.suppress -= 1; } }
  assertPayload(payload) { const keys = [...STATIC_STRINGS, 'browserNav', 'schemaFingerprint', 'params']; if (!plain(payload) || Object.keys(payload).length !== keys.length || !keys.every((key) => Object.hasOwn(payload, key)) || payload.schemaFingerprint !== this.schema.fingerprint || !plain(payload.params)) throw new TypeError('Invalid Menu Item payload.'); const expected = this.schema.fields.map((field) => field.path[1]); if (Object.keys(payload.params).length !== expected.length || !expected.every((key) => Object.hasOwn(payload.params, key))) throw new TypeError('Invalid Menu Item params.'); }
  changed() { if (this.destroyed || this.suppress || !this.callback) return; const next = this.snapshot(); if (JSON.stringify(next) !== JSON.stringify(this.baseline)) { this.baseline = next; this.callback(); } }
  isCurrent() { return !this.destroyed && this.form?.isConnected && this.controls().every((control) => control?.isConnected && this.form.contains(control)); }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; }
}

export { STATIC_STRINGS, validateSchema };
