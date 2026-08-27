const FIELD_KEYS = Object.freeze(['title', 'alias', 'created', 'created_by', 'created_by_alias', 'state', 'w1', 'd1', 'w2', 'd2']);
const MAXIMUM_MAPS = 1000;
const STRING_LIMITS = Object.freeze({ title: 255, alias: 255, created: 255, created_alt: 255, created_by: 10, created_by_alias: 255 });
const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;
const canonicalId = (value) => typeof value === 'string' && /^[1-9][0-9]{0,9}$/.test(value)
  && Number(value) <= 2147483647;

const validatePayload = (payload) => {
  const keys = ['title', 'alias', 'created', 'created_alt', 'created_by', 'created_by_alias', 'state', 'params', 'taxonomy_ids'];
  const params = payload?.params;
  if (!isPlainObject(payload) || Object.keys(payload).length !== keys.length || !keys.every((key) => Object.hasOwn(payload, key))
    || !Object.entries(STRING_LIMITS).every(([key, limit]) => typeof payload[key] === 'string' && Array.from(payload[key]).length <= limit)
    || (payload.created_by !== '' && (!/^(?:0|[1-9][0-9]{0,9})$/.test(payload.created_by) || Number(payload.created_by) > 2147483647))
    || ![0, 1].includes(payload.state) || !isPlainObject(params)
    || Object.keys(params).length !== 6 || !['w1', 'd1', 'd1_alt', 'w2', 'd2', 'd2_alt'].every((key) => Object.hasOwn(params, key))
    || !['', '-1', '0', '1'].includes(params.w1) || !['', '-1', '0', '1'].includes(params.w2)
    || !['d1', 'd1_alt', 'd2', 'd2_alt'].every((key) => typeof params[key] === 'string' && Array.from(params[key]).length <= 255)
    || !Array.isArray(payload.taxonomy_ids) || payload.taxonomy_ids.length > MAXIMUM_MAPS
    || !payload.taxonomy_ids.every(canonicalId) || new Set(payload.taxonomy_ids).size !== payload.taxonomy_ids.length) {
    throw new TypeError('The Finder Filter Autosave recovery payload is invalid.');
  }
  return structuredClone(Object.fromEntries(keys.map((key) => [key, payload[key]])));
};

export default class FilterAutosaveAdapter {
  constructor({ descriptor, form, fields, taxonomySelector = 'input.filter-node[name="t[]"]', eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!isPlainObject(descriptor) || !form || !isPlainObject(fields) || !FIELD_KEYS.every((key) => fields[key])) throw new TypeError('The Finder Filter Autosave adapter configuration is invalid.');
    this.descriptor = Object.freeze({ ...descriptor }); this.form = form; this.fields = { ...fields };
    this.taxonomySelector = taxonomySelector; this.eventFactory = eventFactory; this.baseline = null; this.callback = null;
    this.destroyed = false; this.suppress = 0; this.listener = () => this.changed();
  }
  getDescriptor() { return this.descriptor; }
  nodes() { return [...this.form.querySelectorAll(this.taxonomySelector)]; }
  initializeBaseline() { this.assertCurrent(); this.baseline = this.snapshot(); return this; }
  subscribe(callback) { if (typeof callback !== 'function' || this.callback || this.destroyed) throw new TypeError('Invalid subscription.'); this.callback = callback; [...Object.values(this.fields), ...this.nodes()].forEach((field) => { field.addEventListener('input', this.listener); field.addEventListener('change', this.listener); }); return () => this.unsubscribe(); }
  unsubscribe() { if (!this.form) return; [...Object.values(this.fields), ...this.nodes()].forEach((field) => { field.removeEventListener('input', this.listener); field.removeEventListener('change', this.listener); }); this.callback = null; }
  capture() { this.assertCurrent(); this.baseline = this.snapshot(); return structuredClone(this.baseline); }
  snapshot() {
    return validatePayload({ title: this.fields.title.value, alias: this.fields.alias.value, created: this.fields.created.value,
      created_alt: this.fields.created.getAttribute('data-alt-value') || '', created_by: this.fields.created_by.value,
      created_by_alias: this.fields.created_by_alias.value, state: Number(this.fields.state.value),
      params: { w1: this.fields.w1.value, d1: this.fields.d1.value, d1_alt: this.fields.d1.getAttribute('data-alt-value') || '',
        w2: this.fields.w2.value, d2: this.fields.d2.value, d2_alt: this.fields.d2.getAttribute('data-alt-value') || '' },
      taxonomy_ids: this.nodes().filter((node) => node.checked).map((node) => node.value) });
  }
  apply(payload) { const next = validatePayload(payload); this.assertCurrent(); this.suppress += 1; try {
    ['title', 'alias', 'created_by', 'created_by_alias'].forEach((key) => this.write(this.fields[key], next[key]));
    this.fields.created.setAttribute('data-alt-value', next.created_alt); this.write(this.fields.created, next.created);
    this.write(this.fields.state, String(next.state));
    ['w1', 'w2'].forEach((key) => this.write(this.fields[key], next.params[key]));
    ['d1', 'd2'].forEach((key) => { this.fields[key].setAttribute('data-alt-value', next.params[`${key}_alt`]); this.write(this.fields[key], next.params[key]); });
    const selected = new Set(next.taxonomy_ids); this.nodes().forEach((node) => { const checked = selected.has(node.value); if (node.checked !== checked) { node.checked = checked; node.dispatchEvent(this.eventFactory('change')); } });
    this.baseline = this.snapshot();
  } finally { this.suppress -= 1; } }
  write(control, value) { control.value = value; control.dispatchEvent(this.eventFactory('input')); control.dispatchEvent(this.eventFactory('change')); }
  changed() { if (this.destroyed || this.suppress || !this.callback) return; let current; try { current = this.snapshot(); } catch { return; } if (JSON.stringify(current) === JSON.stringify(this.baseline)) return; this.baseline = current; this.callback(); }
  isCurrent() { return !this.destroyed && this.form?.isConnected && FIELD_KEYS.every((key) => this.fields[key]?.isConnected && this.form.contains(this.fields[key])); }
  assertCurrent() { if (!this.isCurrent()) throw new Error('The Finder Filter form dependencies are stale.'); }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; this.fields = null; }
}

export { FIELD_KEYS, MAXIMUM_MAPS, STRING_LIMITS, validatePayload };
