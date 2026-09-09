const STATIC_STRINGS = Object.freeze(['title', 'name', 'label', 'description', 'default_value', 'note']);
const STATIC_BOOLEANS = Object.freeze(['required', 'only_use_in_subform']);
const dangerous = new Set(['__proto__', 'prototype', 'constructor']);
const plain = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const stringLength = (value) => Array.from(value).length;
const validateSchema = (schema) => {
  if (!plain(schema) || typeof schema.fingerprint !== 'string' || !/^[a-f0-9]{64}$/.test(schema.fingerprint)
    || !Array.isArray(schema.fields) || schema.fields.length > 32) throw new TypeError('Invalid Custom Field Autosave schema.');
  const paths = new Set();
  const fields = schema.fields.map((field) => {
    if (!plain(field) || !Array.isArray(field.path) || field.path.length !== 2 || field.path[0] !== 'fieldparams'
      || field.path.some((part) => typeof part !== 'string' || dangerous.has(part) || !/^[A-Za-z][A-Za-z0-9_-]{0,47}$/.test(part))
      || typeof field.id !== 'string' || !field.id || !['string', 'boolean', 'enum', 'strings', 'rows'].includes(field.kind)) throw new TypeError('Invalid Custom Field Autosave field.');
    const key = field.path.join('\0'); if (paths.has(key)) throw new TypeError('Duplicate Custom Field Autosave path.'); paths.add(key);
    if (field.kind === 'string' && (!Number.isInteger(field.maxLength) || field.maxLength < 1 || field.maxLength > 4096)) throw new TypeError('Invalid Custom Field string bound.');
    if (['enum', 'strings'].includes(field.kind) && (!Array.isArray(field.values) || !field.values.length || field.values.length > 64 || field.values.some((value) => typeof value !== 'string'))) throw new TypeError('Invalid Custom Field enum.');
    if (field.kind === 'rows' && (!plain(field.columns) || !Object.keys(field.columns).length
      || !Number.isInteger(field.maxItems) || field.maxItems < 1 || field.maxItems > 50
      || Object.entries(field.columns).some(([keyName, maximum]) => dangerous.has(keyName) || !/^[A-Za-z][A-Za-z0-9_-]{0,47}$/.test(keyName)
        || !Number.isInteger(maximum) || maximum < 1 || maximum > 4096))) throw new TypeError('Invalid Custom Field row schema.');
    return Object.freeze({ ...field, path: Object.freeze([...field.path]), values: field.values ? Object.freeze([...field.values]) : undefined, columns: field.columns ? Object.freeze({ ...field.columns }) : undefined });
  });
  const support = validateSupport(schema.support || { status: 'supported', reasons: [], parameterless: schema.fields.length === 0 });
  return Object.freeze({ fingerprint: schema.fingerprint, fields: Object.freeze(fields), support });
};
const validateSupport = (support) => {
  if (!plain(support) || !['supported', 'partial', 'unsupported'].includes(support.status)
    || !Array.isArray(support.reasons) || support.reasons.some((reason) => typeof reason !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(reason))
    || typeof support.parameterless !== 'boolean') throw new TypeError('Invalid Custom Field Autosave support status.');
  return Object.freeze({ status: support.status, reasons: Object.freeze([...new Set(support.reasons)]), parameterless: support.parameterless });
};
const boolValue = (controls) => controls.some((control) => control.checked && control.value === '1');
const dispatch = (control, eventFactory) => { control.dispatchEvent(eventFactory('input')); control.dispatchEvent(eventFactory('change')); };

export default class FieldAutosaveAdapter {
  constructor({ descriptor, form, staticFields, dynamicFields, schema, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!plain(descriptor) || !form || !plain(staticFields) || !plain(dynamicFields)) throw new TypeError('Invalid Custom Field Autosave adapter.');
    this.descriptor = Object.freeze({ ...descriptor }); this.form = form; this.staticFields = staticFields; this.dynamicFields = dynamicFields;
    this.schema = validateSchema(schema); this.eventFactory = eventFactory; this.callback = null; this.baseline = null; this.suppress = 0; this.destroyed = false; this.listener = () => this.changed();
    this.rowListener = () => { if (!this.suppress) queueMicrotask(() => this.changed()); };
  }
  getDescriptor() { return this.descriptor; }
  initializeBaseline() { this.baseline = this.snapshot(); return this; }
  subscribe(callback) {
    this.callback = callback;
    this.form.addEventListener('input', this.listener);
    this.form.addEventListener('change', this.listener);
    this.rowHosts().forEach((host) => {
      host.addEventListener('subform-row-add', this.rowListener);
      host.addEventListener('subform-row-remove', this.rowListener);
      host.addEventListener('subform-order-changed', this.rowListener);
    });
    return () => this.unsubscribe();
  }
  unsubscribe() {
    this.form?.removeEventListener?.('input', this.listener);
    this.form?.removeEventListener?.('change', this.listener);
    this.rowHosts().forEach((host) => {
      host.removeEventListener('subform-row-add', this.rowListener);
      host.removeEventListener('subform-row-remove', this.rowListener);
      host.removeEventListener('subform-order-changed', this.rowListener);
    });
    this.callback = null;
  }
  capture() { this.baseline = this.snapshot(); return structuredClone(this.baseline); }
  snapshot() {
    const payload = {};
    STATIC_STRINGS.forEach((key) => { const value = this.staticFields[key].value; if (typeof value !== 'string') throw new TypeError('Invalid Custom Field scalar.'); payload[key] = value; });
    STATIC_BOOLEANS.forEach((key) => { payload[key] = boolValue(this.staticFields[key]); });
    payload.schemaFingerprint = this.schema.fingerprint; payload.fieldparams = {};
    this.schema.fields.forEach((field) => { payload.fieldparams[field.path[1]] = this.readDynamic(field); });
    return payload;
  }
  readDynamic(field) {
    const controls = this.dynamicControls(field);
    if (field.kind === 'boolean') return controls[0].checked;
    if (field.kind === 'enum') return controls.length > 1 ? (controls.find((control) => control.checked)?.value ?? '') : controls[0].value;
    if (field.kind === 'strings') return Array.from(controls[0].selectedOptions || []).map((option) => option.value);
    if (field.kind === 'rows') {
      const rows = this.rowHost(field).getRows();
      if (rows.length > field.maxItems) throw new TypeError('Custom Field option rows exceed their bound.');
      return rows.map((row) => {
        const result = {};
        Object.entries(field.columns).forEach(([column, maximum]) => {
          const matches = [...row.querySelectorAll('[name]')].filter((control) => control.closest('joomla-field-subform') === this.rowHost(field) && control.name.endsWith(`[${column}]`));
          if (matches.length !== 1 || typeof matches[0].value !== 'string' || stringLength(matches[0].value) > maximum) throw new TypeError('Invalid Custom Field option row.');
          result[column] = matches[0].value;
        });
        return result;
      });
    }
    const value = controls[0].value; if (stringLength(value) > field.maxLength) throw new TypeError('Custom Field value exceeds its bound.'); return value;
  }
  apply(payload) { this.suppress += 1; try { this.write(payload); this.baseline = this.snapshot(); } finally { this.suppress -= 1; } }
  write(payload) {
    this.assertPayload(payload);
    STATIC_STRINGS.forEach((key) => { this.staticFields[key].value = payload[key]; dispatch(this.staticFields[key], this.eventFactory); });
    STATIC_BOOLEANS.forEach((key) => { this.staticFields[key].forEach((control) => { control.checked = control.value === (payload[key] ? '1' : '0'); dispatch(control, this.eventFactory); }); });
    this.schema.fields.forEach((field) => {
      const value = payload.fieldparams[field.path[1]]; const controls = this.dynamicControls(field);
      if (field.kind === 'rows') { this.writeRows(field, value); return; }
      if (field.kind === 'strings') { [...controls[0].options].forEach((option) => { option.selected = value.includes(option.value); }); dispatch(controls[0], this.eventFactory); return; }
      if (field.kind === 'boolean') { controls[0].checked = value; dispatch(controls[0], this.eventFactory); return; }
      if (field.kind === 'enum' && controls.length > 1) { controls.forEach((control) => { control.checked = control.value === value; dispatch(control, this.eventFactory); }); return; }
      controls[0].value = value; dispatch(controls[0], this.eventFactory);
    });
  }
  assertPayload(payload) {
    const top = [...STATIC_STRINGS, ...STATIC_BOOLEANS, 'schemaFingerprint', 'fieldparams'];
    if (!plain(payload) || Object.keys(payload).length !== top.length || !top.every((key) => Object.hasOwn(payload, key))
      || payload.schemaFingerprint !== this.schema.fingerprint || !plain(payload.fieldparams)) throw new TypeError('Invalid Custom Field recovery payload.');
    STATIC_STRINGS.forEach((key) => { if (typeof payload[key] !== 'string') throw new TypeError('Invalid Custom Field static value.'); });
    STATIC_BOOLEANS.forEach((key) => { if (typeof payload[key] !== 'boolean') throw new TypeError('Invalid Custom Field boolean.'); });
    const expected = this.schema.fields.map((field) => field.path[1]);
    if (Object.keys(payload.fieldparams).length !== expected.length || !expected.every((key) => Object.hasOwn(payload.fieldparams, key))) throw new TypeError('Unknown Custom Field dynamic value.');
    this.schema.fields.forEach((field) => {
      const value = payload.fieldparams[field.path[1]];
      if (field.kind === 'string' && (typeof value !== 'string' || stringLength(value) > field.maxLength)) throw new TypeError('Invalid Custom Field string.');
      if (field.kind === 'boolean' && typeof value !== 'boolean') throw new TypeError('Invalid Custom Field boolean.');
      if (field.kind === 'enum' && (typeof value !== 'string' || !field.values.includes(value))) throw new TypeError('Invalid Custom Field enum.');
      if (field.kind === 'strings' && (!Array.isArray(value) || value.length > field.maxItems || value.some((item) => !field.values.includes(item)))) throw new TypeError('Invalid Custom Field collection.');
      if (field.kind === 'rows' && (!Array.isArray(value) || value.length > field.maxItems || value.some((row) => !plain(row) || Object.keys(row).length !== Object.keys(field.columns).length || Object.entries(row).some(([key, item]) => !Object.hasOwn(field.columns, key) || typeof item !== 'string' || stringLength(item) > field.columns[key])))) throw new TypeError('Invalid Custom Field rows.');
    });
  }
  changed() { if (this.destroyed || this.suppress || !this.callback) return; const next = this.snapshot(); if (JSON.stringify(next) !== JSON.stringify(this.baseline)) { this.baseline = next; this.callback(); } }
  rowHost(field) {
    const host = this.dynamicFields[field.path[1]];
    if (!host || typeof host.getRows !== 'function' || typeof host.addRow !== 'function' || typeof host.removeRow !== 'function') throw new TypeError('Invalid Custom Field option host.');
    return host;
  }
  rowHosts() { return this.schema.fields.filter((field) => field.kind === 'rows').map((field) => this.rowHost(field)); }
  dynamicControls(field) {
    if (field.kind !== 'rows') return this.dynamicFields[field.path[1]];
    const host = this.rowHost(field);
    return host.getRows().flatMap((row) => [...row.querySelectorAll('[name]')].filter((control) => control.closest('joomla-field-subform') === host));
  }
  writeRows(field, value) {
    const host = this.rowHost(field);
    let rows = host.getRows();
    while (rows.length < value.length) {
      if (!host.addRow(rows.at(-1) || null)) throw new TypeError('Custom Field option rows cannot be restored.');
      rows = host.getRows();
    }
    while (rows.length > value.length) {
      const count = rows.length;
      host.removeRow(rows.at(-1));
      rows = host.getRows();
      if (rows.length >= count) throw new TypeError('Custom Field option rows cannot be restored.');
    }
    rows.forEach((row, index) => {
      Object.keys(field.columns).forEach((column) => {
        const matches = [...row.querySelectorAll('[name]')].filter((control) => control.closest('joomla-field-subform') === host && control.name.endsWith(`[${column}]`));
        if (matches.length !== 1) throw new TypeError('Invalid Custom Field option row.');
        matches[0].value = value[index][column];
        dispatch(matches[0], this.eventFactory);
      });
    });
  }
  controls() { return [...STATIC_STRINGS.map((key) => this.staticFields[key]), ...STATIC_BOOLEANS.flatMap((key) => this.staticFields[key]), ...this.schema.fields.flatMap((field) => (field.kind === 'rows' ? [this.rowHost(field), ...this.dynamicControls(field)] : this.dynamicFields[field.path[1]]))]; }
  isCurrent() { return !this.destroyed && this.form?.isConnected && this.controls().every((control) => control?.isConnected && this.form.contains(control)); }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; }
}

export { STATIC_BOOLEANS, STATIC_STRINGS, validateSchema };
