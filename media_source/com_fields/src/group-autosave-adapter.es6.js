const PAYLOAD_KEYS = Object.freeze(['title', 'note', 'description']);
const LIMITS = Object.freeze({ title: 255, note: 255, description: 65535 });
const plain = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const normalizeCanonicalId = (value) => { const id = Number.isSafeInteger(value) ? String(value) : value; if (typeof id !== 'string' || !/^[1-9][0-9]{0,9}$/.test(id) || Number(id) > 2147483647) throw new TypeError('Invalid Field Group target.'); return id; };
const normalizeAutosaveTarget = (value) => (value === null || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value)) ? value : normalizeCanonicalId(value));
const validatePayload = (payload) => { if (!plain(payload) || Object.keys(payload).length !== PAYLOAD_KEYS.length || !PAYLOAD_KEYS.every((key) => Object.hasOwn(payload, key) && typeof payload[key] === 'string' && Array.from(payload[key]).length <= LIMITS[key])) throw new TypeError('Invalid Field Group recovery payload.'); return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, payload[key]])); };
export default class GroupAutosaveAdapter {
  constructor({ descriptor, form, fields, eventFactory = (type) => new Event(type, { bubbles: true }) }) { if (!plain(descriptor) || !form || !plain(fields) || !PAYLOAD_KEYS.every((key) => fields[key])) throw new TypeError('Invalid Field Group adapter configuration.'); this.descriptor = Object.freeze({ context: descriptor.context, targetId: normalizeAutosaveTarget(descriptor.targetId), payloadSchemaVersion: descriptor.payloadSchemaVersion }); this.form = form; this.fields = { ...fields }; this.eventFactory = eventFactory; this.baseline = null; this.callback = null; this.destroyed = false; this.suppress = 0; this.listeners = Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, () => this.changed()])); }
  getDescriptor() { return this.descriptor; }
  initializeBaseline() { this.assertCurrent(); this.baseline = this.snapshot(); return this; }
  subscribe(callback) { if (typeof callback !== 'function' || this.callback || this.destroyed) throw new TypeError('Invalid Field Group subscription.'); this.callback = callback; PAYLOAD_KEYS.forEach((key) => ['input', 'change'].forEach((type) => this.fields[key].addEventListener(type, this.listeners[key]))); return () => this.unsubscribe(); }
  capture() { this.assertCurrent(); this.baseline = this.snapshot(); return { ...this.baseline }; }
  apply(payload) { const next = validatePayload(payload); this.assertCurrent(); const previous = this.snapshot(); this.suppress += 1; try { this.write(next); this.baseline = this.snapshot(); } catch (error) { try { this.write(previous); this.baseline = previous; } catch (rollbackError) { Object.defineProperty(error, 'rollbackError', { value: rollbackError }); } throw error; } finally { this.suppress -= 1; } }
  snapshot() { return validatePayload(Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, this.fields[key].value]))); }
  write(payload) { PAYLOAD_KEYS.forEach((key) => { this.fields[key].value = payload[key]; this.fields[key].dispatchEvent(this.eventFactory('input')); this.fields[key].dispatchEvent(this.eventFactory('change')); }); }
  changed() { if (this.destroyed || this.suppress || !this.callback || !this.isCurrent()) return; const current = this.snapshot(); if (PAYLOAD_KEYS.every((key) => current[key] === this.baseline?.[key])) return; this.baseline = current; this.callback(); }
  isCurrent() { return !this.destroyed && this.form?.isConnected && PAYLOAD_KEYS.every((key) => this.fields[key]?.isConnected && this.form.contains(this.fields[key])); }
  assertCurrent() { if (!this.isCurrent()) throw new Error('Field Group form is stale.'); }
  unsubscribe() { if (!this.callback) return; PAYLOAD_KEYS.forEach((key) => ['input', 'change'].forEach((type) => this.fields[key].removeEventListener(type, this.listeners[key]))); this.callback = null; }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; this.fields = null; }
}
export { LIMITS, PAYLOAD_KEYS, normalizeCanonicalId, validatePayload };
