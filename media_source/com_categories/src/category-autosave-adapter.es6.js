const PAYLOAD_KEYS = Object.freeze(['title', 'note', 'description', 'version_note', 'metadesc', 'metakey', 'parent_id']);
const TEXT_KEYS = Object.freeze(PAYLOAD_KEYS.filter((key) => key !== 'description' && key !== 'parent_id'));
const LEGACY_KEYS = Object.freeze(['title', 'note', 'description', 'version_note', 'metadesc', 'metakey']);
const LIMITS = Object.freeze({ title: 255, note: 255, description: 65535, version_note: 255, metadesc: 300, metakey: 1024 });
const plain = (value) => value !== null && typeof value === 'object' && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;
const normalizeCanonicalId = (value) => { const id = Number.isSafeInteger(value) ? String(value) : value; if (typeof id !== 'string' || !/^[1-9][0-9]{0,9}$/.test(id) || Number(id) > 2147483647) throw new TypeError('Invalid Category target.'); return id; };
const normalizeAutosaveTarget = (value) => (value === null || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value)) ? value : normalizeCanonicalId(value));
const parseParent = (value) => { if (typeof value !== 'string' || !/^[1-9][0-9]{0,9}$/.test(value) || Number(value) > 2147483647) throw new TypeError('Invalid Category parent.'); return Number(value); };
const validTextPayload = (payload, keys) => keys.every((key) => typeof payload[key] === 'string' && Array.from(payload[key]).length <= LIMITS[key]);
const validatePayload = (payload) => {
  if (!plain(payload)) throw new TypeError('Invalid Category recovery payload.');

  if (Object.keys(payload).length === LEGACY_KEYS.length
    && LEGACY_KEYS.every((key) => Object.hasOwn(payload, key))
    && validTextPayload(payload, LEGACY_KEYS)) {
    return Object.fromEntries(LEGACY_KEYS.map((key) => [key, payload[key]]));
  }

  if (Object.keys(payload).length === PAYLOAD_KEYS.length
    && PAYLOAD_KEYS.every((key) => Object.hasOwn(payload, key))
    && validTextPayload(payload, PAYLOAD_KEYS.filter((key) => key !== 'parent_id'))
    && Number.isSafeInteger(payload.parent_id)
    && payload.parent_id >= 1
    && payload.parent_id <= 2147483647) {
    return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, payload[key]]));
  }

  throw new TypeError('Invalid Category recovery payload.');
};
const snapshotMatches = (expected, applied) => Object.keys(expected).every((key) => expected[key] === applied[key]);

export default class CategoryAutosaveAdapter {
  constructor({ descriptor, form, fields, editor, getCurrentEditor, eventFactory = (type) => new Event(type, { bubbles: true }) }) { if (!plain(descriptor) || !form || !plain(fields) || !PAYLOAD_KEYS.every((key) => fields[key]) || !editor || typeof editor.getValue !== 'function' || typeof editor.setValue !== 'function' || typeof editor.subscribeChange !== 'function' || typeof getCurrentEditor !== 'function') throw new TypeError('Invalid Category adapter configuration.'); this.descriptor = Object.freeze({ context: descriptor.context, targetId: normalizeAutosaveTarget(descriptor.targetId), payloadSchemaVersion: descriptor.payloadSchemaVersion }); this.form = form; this.fields = { ...fields }; this.editor = editor; this.getCurrentEditor = getCurrentEditor; this.editorId = fields.description.id; this.eventFactory = eventFactory; this.baseline = null; this.callback = null; this.destroyed = false; this.suppress = 0; this.listeners = Object.fromEntries([...TEXT_KEYS, 'parent_id'].map((key) => [key, () => this.changed()])); this.editorListener = () => this.changed(); }
  getDescriptor() { return this.descriptor; }
  initializeBaseline() { this.assertCurrent(); this.baseline = this.snapshot(); return this; }
  subscribe(callback) { if (typeof callback !== 'function' || this.callback || this.destroyed) throw new TypeError('Invalid Category subscription.'); this.callback = callback; [...TEXT_KEYS, 'parent_id'].forEach((key) => ['input', 'change'].forEach((type) => this.fields[key].addEventListener(type, this.listeners[key]))); this.unsubscribeEditor = this.editor.subscribeChange(this.editorListener); if (typeof this.unsubscribeEditor !== 'function') { this.unsubscribe(); throw new TypeError('Invalid Category editor subscription.'); } return () => this.unsubscribe(); }
  capture() { this.assertCurrent(); this.baseline = this.snapshot(); return { ...this.baseline }; }
  apply(payload) { const next = validatePayload(payload); this.assertCurrent(); const previous = this.snapshot(); this.suppress += 1; try { this.write(next); const applied = this.snapshot(); if (!snapshotMatches(next, applied)) throw new Error('Category recovery failed.'); this.baseline = applied; } catch (error) { try { this.write(previous); this.baseline = previous; } catch (rollbackError) { Object.defineProperty(error, 'rollbackError', { value: rollbackError }); } throw error; } finally { this.suppress -= 1; } }
  snapshot() { const payload = Object.fromEntries(TEXT_KEYS.map((key) => [key, this.fields[key].value])); payload.description = this.editor.getValue(); payload.parent_id = parseParent(this.fields.parent_id.value); return validatePayload(payload); }
  write(payload) { TEXT_KEYS.forEach((key) => { this.fields[key].value = payload[key]; this.fields[key].dispatchEvent(this.eventFactory('input')); this.fields[key].dispatchEvent(this.eventFactory('change')); }); if (Object.hasOwn(payload, 'parent_id')) { this.fields.parent_id.value = String(payload.parent_id); this.fields.parent_id.dispatchEvent(this.eventFactory('input')); this.fields.parent_id.dispatchEvent(this.eventFactory('change')); } this.editor.setValue(payload.description); }
  changed() { if (this.destroyed || this.suppress || !this.callback || !this.isCurrent()) return; let current; try { current = this.snapshot(); } catch { return; } if (PAYLOAD_KEYS.every((key) => current[key] === this.baseline?.[key])) return; this.baseline = current; this.callback(); }
  isCurrent() { return !this.destroyed && this.form?.isConnected && PAYLOAD_KEYS.every((key) => this.fields[key]?.isConnected && this.form.contains(this.fields[key])) && this.getCurrentEditor(this.editorId) === this.editor; }
  assertCurrent() { if (!this.isCurrent()) throw new Error('Category form or editor is stale.'); }
  unsubscribe() { if (!this.callback && !this.unsubscribeEditor) return; [...TEXT_KEYS, 'parent_id'].forEach((key) => ['input', 'change'].forEach((type) => this.fields[key].removeEventListener(type, this.listeners[key]))); this.callback = null; const unsubscribe = this.unsubscribeEditor; this.unsubscribeEditor = null; unsubscribe?.(); }
  destroy() { if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; this.fields = null; this.editor = null; this.getCurrentEditor = null; }
}
export { LEGACY_KEYS, LIMITS, PAYLOAD_KEYS, normalizeCanonicalId, parseParent, validatePayload };
