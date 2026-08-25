const KEYS = Object.freeze(['title', 'description']);
const LIMITS = Object.freeze({ title: 255, description: 65535 });
const plain = (v) => v !== null && typeof v === 'object' && !Array.isArray(v) && Object.getPrototypeOf(v) === Object.prototype;
export const normalizeCanonicalId = (v) => { const id = Number.isSafeInteger(v) ? String(v) : v; if (typeof id !== 'string' || !/^[1-9][0-9]{0,9}$/.test(id) || Number(id) > 2147483647) throw new TypeError('Invalid Workflow target.'); return id; };
export const validatePayload = (v) => { if (!plain(v) || Object.keys(v).length !== KEYS.length || !KEYS.every((k) => Object.hasOwn(v, k) && typeof v[k] === 'string' && Array.from(v[k]).length <= LIMITS[k])) throw new TypeError('Invalid Workflow payload.'); return { title: v.title, description: v.description }; };
export default class WorkflowAutosaveAdapter {
  constructor({ descriptor, form, fields, eventFactory = (t) => new Event(t, { bubbles: true }) }) { if (!plain(descriptor) || !form || !plain(fields) || !KEYS.every((k) => fields[k])) throw new TypeError('Invalid Workflow adapter.'); this.descriptor = Object.freeze({ ...descriptor, targetId: normalizeCanonicalId(descriptor.targetId) }); this.form = form; this.fields = { ...fields }; this.eventFactory = eventFactory; this.baseline = null; this.callback = null; this.destroyed = false; this.suppressChanges = 0; this.listeners = Object.fromEntries(KEYS.map((k) => [k, () => this.changed(k)])); }
  getDescriptor() { return this.descriptor; } initializeBaseline() { this.assertCurrent(); this.baseline = this.read(); return this; }
  subscribe(cb) { if (typeof cb !== 'function' || this.callback || this.destroyed) throw new TypeError('Invalid Workflow subscription.'); if (!this.baseline) this.initializeBaseline(); this.callback = cb; KEYS.forEach((k) => ['input', 'change'].forEach((t) => this.fields[k].addEventListener(t, this.listeners[k]))); return () => this.unsubscribe(); }
  capture() { this.assertCurrent(); this.baseline = this.read(); return { ...this.baseline }; }
  apply(payload) { const next = validatePayload(payload); this.assertCurrent(); const previous = this.read(); this.suppressChanges += 1; try { KEYS.forEach((k) => { this.fields[k].value = next[k]; this.fields[k].dispatchEvent(this.eventFactory('input')); this.fields[k].dispatchEvent(this.eventFactory('change')); }); this.baseline = this.read(); } catch (error) { KEYS.forEach((k) => { this.fields[k].value = previous[k]; }); this.baseline = previous; throw error; } finally { this.suppressChanges -= 1; } }
  changed(k) { if (this.suppressChanges || !this.callback || !this.isCurrent()) return; const value = this.fields[k].value; if (this.baseline?.[k] === value) return; this.baseline = { ...this.baseline, [k]: value }; this.callback(); }
  read() { return { title: this.fields.title.value, description: this.fields.description.value }; }
  isCurrent() { return !this.destroyed && this.form?.isConnected && KEYS.every((k) => this.fields[k]?.isConnected && this.form.contains(this.fields[k])); } assertCurrent() { if (!this.isCurrent()) throw new Error('Workflow form is stale.'); }
  unsubscribe() { if (!this.callback) return; KEYS.forEach((k) => ['input', 'change'].forEach((t) => this.fields[k].removeEventListener(t, this.listeners[k]))); this.callback = null; }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; this.fields = null; }
}
export { KEYS as PAYLOAD_KEYS, LIMITS as MAXIMUM_LENGTHS };
