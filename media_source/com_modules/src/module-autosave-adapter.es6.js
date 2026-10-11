const plain = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const dangerous = new Set(['__proto__', 'prototype', 'constructor']);
const validateSchema = (schema) => {
  if (!plain(schema) || !/^[a-f0-9]{64}$/.test(schema.fingerprint) || !Array.isArray(schema.fields) || schema.fields.length > 32) throw new TypeError('Invalid Module schema.');
  const paths = new Set();
  const fields = schema.fields.map((field) => {
    if (!plain(field) || !Array.isArray(field.path) || field.path.length !== 2 || field.path[0] !== 'params'
      || field.path.some((part) => typeof part !== 'string' || dangerous.has(part) || !/^[A-Za-z][A-Za-z0-9_-]{0,47}$/.test(part))
      || typeof field.id !== 'string' || !field.id || !['string', 'boolean', 'enum', 'strings'].includes(field.kind)) throw new TypeError('Invalid Module schema field.');
    const key = field.path.join('\0');
    if (paths.has(key)) throw new TypeError('Duplicate Module schema path.');
    paths.add(key);
    if (field.kind === 'string' && (!Number.isInteger(field.maxLength) || field.maxLength < 1 || field.maxLength > 4096)) throw new TypeError('Invalid Module string bound.');
    if (['enum', 'strings'].includes(field.kind) && (!Array.isArray(field.values) || !field.values.length || field.values.length > 64 || field.values.some((value) => typeof value !== 'string'))) throw new TypeError('Invalid Module enum.');
    if (field.kind === 'strings' && (!Number.isInteger(field.maxItems) || field.maxItems < 1 || field.maxItems > 50)) throw new TypeError('Invalid Module collection bound.');
    return Object.freeze({ ...field, path: Object.freeze([...field.path]), values: field.values ? Object.freeze([...field.values]) : undefined });
  });
  const support = validateSupport(schema.support || { status: 'supported', reasons: [], parameterless: schema.fields.length === 0 });
  return Object.freeze({ fingerprint: schema.fingerprint, fields: Object.freeze(fields), support });
};
const validateSupport = (support) => {
  if (!plain(support) || !['supported', 'partial', 'unsupported'].includes(support.status)
    || !Array.isArray(support.reasons) || support.reasons.some((reason) => typeof reason !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(reason))
    || typeof support.parameterless !== 'boolean') throw new TypeError('Invalid Module support status.');
  return Object.freeze({ status: support.status, reasons: Object.freeze([...new Set(support.reasons)]), parameterless: support.parameterless });
};
const dynamicValue = (field, controls) => field.kind === 'boolean' ? controls[0].checked : field.kind === 'strings' ? [...controls[0].selectedOptions].map((option) => option.value) : field.kind === 'enum' && controls.length > 1 ? controls.find((control) => control.checked)?.value ?? '' : controls[0].value;
const validDynamicValue = (field, value) => {
  if (field.kind === 'boolean') return typeof value === 'boolean';
  if (field.kind === 'string') return typeof value === 'string' && Array.from(value).length <= field.maxLength;
  if (field.kind === 'enum') return typeof value === 'string' && field.values.includes(value);
  return Array.isArray(value) && value.length <= field.maxItems && value.every((item) => field.values.includes(item));
};
export default class ModuleAutosaveAdapter {
  constructor({ descriptor, form, fields, dynamicFields, schema, editorProvider = (id) => globalThis.Joomla?.editors?.instances?.[id] }) { this.descriptor = Object.freeze({ ...descriptor }); this.form = form; this.fields = fields; this.dynamicFields = dynamicFields; this.schema = validateSchema(schema); this.editorProvider = editorProvider; this.callback = null; this.listener = () => this.changed(); this.baseline = null; }
  getDescriptor() { return this.descriptor; }
  controls() { return [...Object.values(this.fields).filter(Boolean).flatMap((field) => Array.isArray(field) ? field : [field]), ...Object.values(this.dynamicFields).flat()]; }
  snapshot() { const contentEditor = this.fields.content ? this.editorProvider(this.fields.content.id) : null; const payload = { title: this.fields.title.value, note: this.fields.note.value, version_note: this.fields.version_note.value, showtitle: this.fields.showtitle.find((control) => control.checked)?.value ?? '', position: this.fields.position.value, content: contentEditor?.getValue?.() ?? this.fields.content?.value ?? '', schemaFingerprint: this.schema.fingerprint, params: {} }; this.schema.fields.forEach((field) => { payload.params[field.path[1]] = dynamicValue(field, this.dynamicFields[field.path[1]]); }); return payload; }
  initializeBaseline() { this.baseline = this.snapshot(); return this; }
  capture() { this.baseline = this.snapshot(); return structuredClone(this.baseline); }
  subscribe(callback) { this.callback = callback; this.controls().forEach((control) => { control.addEventListener('input', this.listener); control.addEventListener('change', this.listener); }); const editor = this.fields.content && this.editorProvider(this.fields.content.id); editor?.on?.('change', this.listener); return () => this.unsubscribe(); }
  unsubscribe() { this.controls().forEach((control) => { control.removeEventListener('input', this.listener); control.removeEventListener('change', this.listener); }); this.callback = null; }
  changed() { if (!this.callback) return; const next = this.snapshot(); if (JSON.stringify(next) !== JSON.stringify(this.baseline)) { this.baseline = next; this.callback(); } }
  assertPayload(payload) {
    const keys = ['title', 'note', 'version_note', 'showtitle', 'position', 'content', 'schemaFingerprint', 'params'];
    if (!plain(payload) || Object.keys(payload).length !== keys.length || !keys.every((key) => Object.hasOwn(payload, key))
      || payload.schemaFingerprint !== this.schema.fingerprint || !plain(payload.params)) throw new TypeError('Invalid Module recovery payload.');
    const limits = { title: 100, note: 255, version_note: 255, position: 50, content: 65535 };
    Object.entries(limits).forEach(([key, limit]) => { if (typeof payload[key] !== 'string' || Array.from(payload[key]).length > limit) throw new TypeError('Invalid Module scalar.'); });
    if (!['0', '1'].includes(payload.showtitle)) throw new TypeError('Invalid Module show-title value.');
    const expected = this.schema.fields.map((field) => field.path[1]);
    if (Object.keys(payload.params).length !== expected.length || !expected.every((key) => Object.hasOwn(payload.params, key))) throw new TypeError('Invalid Module params.');
    this.schema.fields.forEach((field) => { if (!validDynamicValue(field, payload.params[field.path[1]])) throw new TypeError('Invalid Module param.'); });
  }
  apply(payload) { this.assertPayload(payload); ['title','note','version_note','position'].forEach((key) => { this.fields[key].value = payload[key]; this.fields[key].dispatchEvent(new Event('change', { bubbles: true })); }); this.fields.showtitle.forEach((control) => { control.checked=control.value===payload.showtitle; control.dispatchEvent(new Event('change',{bubbles:true})); }); const editor = this.fields.content && this.editorProvider(this.fields.content.id); if (editor?.setValue) editor.setValue(payload.content); else if (this.fields.content) this.fields.content.value = payload.content; this.schema.fields.forEach((field) => { const controls=this.dynamicFields[field.path[1]], value=payload.params[field.path[1]]; if(field.kind==='boolean') controls[0].checked=value; else if(field.kind==='strings') [...controls[0].options].forEach((o)=>{o.selected=value.includes(o.value);}); else if(field.kind==='enum'&&controls.length>1) controls.forEach((c)=>{c.checked=c.value===value;}); else controls[0].value=value; controls.forEach((c)=>c.dispatchEvent(new Event('change',{bubbles:true}))); }); this.baseline=this.snapshot(); }
  isCurrent() { return this.form?.isConnected && this.controls().every((control) => control.isConnected && this.form.contains(control)); }
  destroy() { this.unsubscribe(); this.form = null; }
}
export { validateSchema };
