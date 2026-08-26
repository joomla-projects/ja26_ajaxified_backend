/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const STRING_KEYS = Object.freeze([
  'name', 'alias', 'version_note', 'misc', 'image', 'con_position', 'email_to', 'address',
  'suburb', 'state', 'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage',
  'sortname1', 'sortname2', 'sortname3', 'publish_up', 'publish_up_alt', 'publish_down',
  'publish_down_alt', 'metakey', 'metadesc',
]);
const ORDINARY_KEYS = Object.freeze([
  'name', 'alias', 'version_note', 'con_position', 'email_to', 'address', 'suburb', 'state',
  'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage', 'sortname1', 'sortname2',
  'sortname3', 'metakey', 'metadesc',
]);
const DATE_KEYS = Object.freeze(['publish_up', 'publish_down']);
const LIMITS = Object.freeze({
  name: 255, alias: 255, version_note: 255, misc: 65535, image: 255, con_position: 255,
  email_to: 255, address: 65535, suburb: 100, state: 100, postcode: 100, country: 100,
  telephone: 255, mobile: 255, fax: 255, webpage: 255, sortname1: 255, sortname2: 255,
  sortname3: 255, publish_up: 255, publish_up_alt: 255, publish_down: 255,
  publish_down_alt: 255, metakey: 65535, metadesc: 300,
});

const isPlainObject = (value) => value !== null && typeof value === 'object'
  && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;

const normalizeCanonicalId = (value) => {
  const candidate = Number.isSafeInteger(value) ? String(value) : value;
  if (typeof candidate !== 'string' || !/^[1-9][0-9]{0,9}$/.test(candidate) || Number(candidate) > 2147483647) {
    throw new TypeError('The Contact Autosave target is invalid.');
  }
  return candidate;
};

const isStableMediaReference = (reference) => {
  if (reference === '') return true;
  if (reference.includes('\0') || reference.includes('\\') || reference.startsWith('//')) return false;
  if (/^(?:blob|data|file):/i.test(reference)) return false;
  if (!/^[a-z][a-z0-9+.-]*:/i.test(reference)) return !reference.startsWith('/');
  try {
    const url = new URL(reference);
    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && Boolean(url.hostname);
  } catch {
    return false;
  }
};

const validatePayload = (payload) => {
  if (!isPlainObject(payload) || Object.keys(payload).length !== STRING_KEYS.length
    || !STRING_KEYS.every((key) => Object.hasOwn(payload, key) && typeof payload[key] === 'string'
      && Array.from(payload[key]).length <= LIMITS[key])
    || !isStableMediaReference(payload.image)) {
    throw new TypeError('The Contact Autosave recovery payload is invalid.');
  }
  return Object.fromEntries(STRING_KEYS.map((key) => [key, payload[key]]));
};

export default class ContactAutosaveAdapter {
  constructor({ descriptor, form, fields, editor, getCurrentEditor, mediaField, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!isPlainObject(descriptor) || !form || !isPlainObject(fields)
      || ![...ORDINARY_KEYS, ...DATE_KEYS, 'misc', 'image'].every((key) => fields[key])
      || !editor?.getValue || !editor?.setValue || !editor?.subscribeChange
      || typeof getCurrentEditor !== 'function' || !mediaField || typeof mediaField.setValue !== 'function') {
      throw new TypeError('The Contact Autosave adapter configuration is invalid.');
    }
    this.descriptor = Object.freeze({ context: descriptor.context, targetId: normalizeCanonicalId(descriptor.targetId), payloadSchemaVersion: descriptor.payloadSchemaVersion });
    this.form = form; this.fields = { ...fields }; this.editor = editor; this.editorId = fields.misc.id;
    this.getCurrentEditor = getCurrentEditor; this.mediaField = mediaField; this.eventFactory = eventFactory;
    this.baseline = null; this.callback = null; this.unsubscribeEditor = null; this.destroyed = false; this.suppress = 0;
    this.listeners = Object.fromEntries([...ORDINARY_KEYS, ...DATE_KEYS, 'image'].map((key) => [key, () => this.changed()]));
    this.editorListener = () => this.changed();
  }

  getDescriptor() { return this.descriptor; }
  initializeBaseline() { this.assertCurrent(); this.baseline = this.snapshot(); return this; }

  subscribe(callback) {
    if (typeof callback !== 'function' || this.callback || this.destroyed) throw new TypeError('The Contact Autosave subscription is invalid.');
    this.callback = callback;
    [...ORDINARY_KEYS, ...DATE_KEYS, 'image'].forEach((key) => {
      this.fields[key].addEventListener('input', this.listeners[key]);
      this.fields[key].addEventListener('change', this.listeners[key]);
    });
    this.unsubscribeEditor = this.editor.subscribeChange(this.editorListener);
    if (typeof this.unsubscribeEditor !== 'function') { this.unsubscribe(); throw new TypeError('The Contact editor subscription is invalid.'); }
    return () => this.unsubscribe();
  }

  capture() { this.assertCurrent(); this.baseline = this.snapshot(); return { ...this.baseline }; }

  apply(payload) {
    const next = validatePayload(payload); this.assertCurrent(); const previous = this.snapshot(); this.suppress += 1;
    try {
      this.write(next);
      const applied = this.snapshot();
      if (!STRING_KEYS.every((key) => applied[key] === next[key])) throw new Error('The Contact recovery payload could not be applied.');
      this.baseline = applied;
    } catch (error) {
      try { this.write(previous); this.baseline = previous; } catch (rollbackError) { Object.defineProperty(error, 'rollbackError', { value: rollbackError }); }
      throw error;
    } finally { this.suppress -= 1; }
  }

  snapshot() {
    const payload = Object.fromEntries(ORDINARY_KEYS.map((key) => [key, this.fields[key].value]));
    payload.misc = this.editor.getValue(); payload.image = this.fields.image.value;
    DATE_KEYS.forEach((key) => { payload[key] = this.fields[key].value; payload[`${key}_alt`] = this.fields[key].getAttribute('data-alt-value') || ''; });
    return validatePayload(payload);
  }

  write(payload) {
    ORDINARY_KEYS.forEach((key) => this.writeControl(this.fields[key], payload[key]));
    this.editor.setValue(payload.misc);
    DATE_KEYS.forEach((key) => { this.fields[key].setAttribute('data-alt-value', payload[`${key}_alt`]); this.writeControl(this.fields[key], payload[key]); });
    this.mediaField.setValue(payload.image);
  }

  writeControl(control, value) { control.value = value; control.dispatchEvent(this.eventFactory('input')); control.dispatchEvent(this.eventFactory('change')); }

  changed() {
    if (this.destroyed || this.suppress || !this.callback || !this.isCurrent()) return;
    let current; try { current = this.snapshot(); } catch { return; }
    if (STRING_KEYS.every((key) => current[key] === this.baseline?.[key])) return;
    this.baseline = current; this.callback();
  }

  isCurrent() {
    return !this.destroyed && this.form?.isConnected && this.fields
      && [...ORDINARY_KEYS, ...DATE_KEYS, 'misc', 'image'].every((key) => this.fields[key]?.isConnected && this.form.contains(this.fields[key]))
      && this.mediaField.isConnected && this.mediaField.contains(this.fields.image)
      && this.getCurrentEditor(this.editorId) === this.editor;
  }

  assertCurrent() { if (!this.isCurrent()) throw new Error('The Contact form dependencies are stale.'); }

  unsubscribe() {
    [...ORDINARY_KEYS, ...DATE_KEYS, 'image'].forEach((key) => {
      this.fields[key].removeEventListener('input', this.listeners[key]);
      this.fields[key].removeEventListener('change', this.listeners[key]);
    });
    this.callback = null; const unsubscribe = this.unsubscribeEditor; this.unsubscribeEditor = null; unsubscribe?.();
  }

  destroy() {
    if (this.destroyed) return; this.unsubscribe(); this.destroyed = true; this.form = null; this.fields = null;
    this.editor = null; this.getCurrentEditor = null; this.mediaField = null;
  }
}

export { DATE_KEYS, LIMITS, ORDINARY_KEYS, STRING_KEYS, isStableMediaReference, normalizeCanonicalId, validatePayload };
