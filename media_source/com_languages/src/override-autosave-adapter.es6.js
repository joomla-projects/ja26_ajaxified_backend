const KEYS = Object.freeze(['key', 'override', 'both']);
const valid = (payload) => {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)
    || Object.keys(payload).length !== KEYS.length || !KEYS.every((key) => Object.hasOwn(payload, key))
    || typeof payload.key !== 'string' || Array.from(payload.key).length > 110
    || typeof payload.override !== 'string' || Array.from(payload.override).length > 65535
    || typeof payload.both !== 'boolean') throw new TypeError('Invalid Language Override draft.');
  return Object.freeze({ key: payload.key, override: payload.override, both: payload.both });
};

export default class OverrideAutosaveAdapter {
  constructor({ descriptor, form, fields, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!descriptor || !form || !fields || !KEYS.every((key) => fields[key])) throw new TypeError('Invalid Language Override adapter.');
    this.descriptor = Object.freeze({ ...descriptor }); this.form = form; this.fields = { ...fields }; this.eventFactory = eventFactory;
    this.baseline = null; this.callback = null; this.destroyed = false; this.suppress = 0; this.listener = () => this.changed();
  }
  getDescriptor() { return this.descriptor; }
  snapshot() { return valid({ key: this.fields.key.value, override: this.fields.override.value, both: this.fields.both.checked }); }
  initializeBaseline() { this.baseline = this.snapshot(); return this; }
  subscribe(callback) { this.callback = callback; Object.values(this.fields).forEach((field) => { field.addEventListener('input', this.listener); field.addEventListener('change', this.listener); }); return () => this.unsubscribe(); }
  unsubscribe() { if (!this.fields) return; Object.values(this.fields).forEach((field) => { field.removeEventListener('input', this.listener); field.removeEventListener('change', this.listener); }); this.callback = null; }
  capture() { this.baseline = this.snapshot(); return { ...this.baseline }; }
  apply(payload) {
    const next = valid(payload); this.suppress += 1;
    try {
      ['key', 'override'].forEach((key) => { if (this.fields[key].value !== next[key]) { this.fields[key].value = next[key]; this.fields[key].dispatchEvent(this.eventFactory('input')); this.fields[key].dispatchEvent(this.eventFactory('change')); } });
      if (this.fields.both.checked !== next.both) { this.fields.both.checked = next.both; this.fields.both.dispatchEvent(this.eventFactory('change')); }
      this.baseline = this.snapshot();
    } finally { this.suppress -= 1; }
  }
  changed() { if (this.destroyed || this.suppress || !this.callback) return; const next = this.snapshot(); if (JSON.stringify(next) !== JSON.stringify(this.baseline)) { this.baseline = next; this.callback(); } }
  isCurrent() { return !this.destroyed && this.form?.isConnected && KEYS.every((key) => this.fields[key]?.isConnected && this.form.contains(this.fields[key])); }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; this.fields = null; }
}

export { KEYS, valid as validatePayload };
