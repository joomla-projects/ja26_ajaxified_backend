/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const STRING_KEYS = Object.freeze([
  'name', 'alias', 'description', 'custombannercode', 'clickurl', 'version_note',
  'publish_up', 'publish_up_alt', 'publish_down', 'publish_down_alt', 'imageurl',
  'width', 'height', 'alt', 'metakey', 'metakey_prefix',
]);
const INTEGER_KEYS = Object.freeze(['type', 'own_prefix', 'catid', 'cid']);
const PAYLOAD_KEYS = Object.freeze([...STRING_KEYS, ...INTEGER_KEYS]);
const ORDINARY_FIELD_KEYS = Object.freeze([
  'name', 'alias', 'custombannercode', 'clickurl', 'version_note', 'width', 'height',
  'alt', 'metakey', 'metakey_prefix',
]);
const DATE_KEYS = Object.freeze(['publish_up', 'publish_down']);
const MAXIMUM_LENGTHS = Object.freeze({
  name: 255,
  alias: 255,
  description: 65535,
  custombannercode: 2048,
  clickurl: 2048,
  version_note: 255,
  publish_up: 255,
  publish_up_alt: 255,
  publish_down: 255,
  publish_down_alt: 255,
  imageurl: 2048,
  width: 10,
  height: 10,
  alt: 255,
  metakey: 65535,
  metakey_prefix: 255,
});
const INTEGER_VALUES = Object.freeze({
  type: Object.freeze([0, 1]),
  own_prefix: Object.freeze([0, 1]),
  catid: null,
  cid: null,
});

const isPlainObject = (value) => value !== null && typeof value === 'object'
  && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;

const normalizeCanonicalId = (value) => {
  const candidate = Number.isSafeInteger(value) ? String(value) : value;
  if (typeof candidate !== 'string' || !/^[1-9][0-9]{0,9}$/.test(candidate) || Number(candidate) > 2147483647) {
    throw new TypeError('The Banner Autosave target is invalid.');
  }
  return candidate;
};
const normalizeAutosaveTarget = (value) => (value === null
  || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value))
  ? value : normalizeCanonicalId(value));

const validRelationInteger = (key, integer) => (key === 'cid' ? integer >= 0 : integer > 0);

const parseInteger = (value, key) => {
  if (typeof value !== 'string' || !/^(?:0|[1-9][0-9]*)$/.test(value)) {
    throw new TypeError('The Banner Autosave integer field is invalid.');
  }
  const integer = Number(value);
  if (!Number.isSafeInteger(integer)
    || (INTEGER_VALUES[key] ? !INTEGER_VALUES[key].includes(integer) : !validRelationInteger(key, integer))) {
    throw new TypeError('The Banner Autosave integer field is invalid.');
  }
  return integer;
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

const validDimension = (value) => value === ''
  || (/^(?:0|[1-9][0-9]{0,9})$/.test(value) && Number(value) <= 2147483647);

const validatePayload = (payload) => {
  if (!isPlainObject(payload) || Object.keys(payload).length !== PAYLOAD_KEYS.length
    || !PAYLOAD_KEYS.every((key) => Object.hasOwn(payload, key))
    || !STRING_KEYS.every((key) => typeof payload[key] === 'string'
      && Array.from(payload[key]).length <= MAXIMUM_LENGTHS[key])
    || !INTEGER_KEYS.every((key) => Number.isInteger(payload[key])
      && (INTEGER_VALUES[key] ? INTEGER_VALUES[key].includes(payload[key]) : validRelationInteger(key, payload[key])))
    || !validDimension(payload.width) || !validDimension(payload.height)
    || !isStableMediaReference(payload.imageurl)) {
    throw new TypeError('The Banner Autosave recovery payload is invalid.');
  }
  return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, payload[key]]));
};

export default class BannerAutosaveAdapter {
  constructor({
    descriptor,
    form,
    fields,
    editor,
    getCurrentEditor,
    mediaField,
    eventFactory = (type) => new Event(type, { bubbles: true }),
  }) {
    if (!isPlainObject(descriptor) || !form || !isPlainObject(fields)
      || ![...ORDINARY_FIELD_KEYS, ...DATE_KEYS, 'description', 'imageurl', 'type', 'own_prefix', 'catid', 'cid']
        .every((key) => fields[key])
      || !editor?.getValue || !editor?.setValue || !editor?.subscribeChange
      || typeof getCurrentEditor !== 'function' || !mediaField
      || typeof mediaField.setValue !== 'function' || typeof eventFactory !== 'function') {
      throw new TypeError('The Banner Autosave adapter configuration is invalid.');
    }

    this.descriptor = Object.freeze({
      context: descriptor.context,
      targetId: normalizeAutosaveTarget(descriptor.targetId),
      payloadSchemaVersion: descriptor.payloadSchemaVersion,
    });
    this.form = form;
    this.fields = { ...fields };
    this.editor = editor;
    this.editorId = fields.description.id;
    this.getCurrentEditor = getCurrentEditor;
    this.mediaField = mediaField;
    this.eventFactory = eventFactory;
    this.baseline = null;
    this.callback = null;
    this.unsubscribeEditor = null;
    this.destroyed = false;
    this.suppress = 0;
    this.listeners = Object.fromEntries(
      [...ORDINARY_FIELD_KEYS, ...DATE_KEYS, 'imageurl', 'type', 'own_prefix', 'catid', 'cid']
        .map((key) => [key, () => this.changed()]),
    );
    this.editorListener = () => this.changed();
  }

  getDescriptor() { return this.descriptor; }

  initializeBaseline() {
    this.assertCurrent();

    try {
      this.baseline = this.snapshot();
    } catch {
      // A new Banner form may not yet hold a numeric category/client
      // selection. Keep the runtime armed and let the first valid change
      // establish the baseline instead of failing the bootstrap.
      this.baseline = null;
    }

    return this;
  }

  subscribe(callback) {
    if (typeof callback !== 'function' || this.callback || this.destroyed) {
      throw new TypeError('The Banner Autosave subscription is invalid.');
    }
    this.callback = callback;
    this.observableKeys().forEach((key) => this.controls(key).forEach((control) => {
      control.addEventListener('input', this.listeners[key]);
      control.addEventListener('change', this.listeners[key]);
    }));
    this.unsubscribeEditor = this.editor.subscribeChange(this.editorListener);
    if (typeof this.unsubscribeEditor !== 'function') {
      this.unsubscribe();
      throw new TypeError('The Banner editor subscription is invalid.');
    }
    return () => this.unsubscribe();
  }

  capture() { this.assertCurrent(); this.baseline = this.snapshot(); return { ...this.baseline }; }

  apply(payload) {
    const next = validatePayload(payload);
    this.assertCurrent();
    const previous = this.snapshot();
    this.suppress += 1;
    try {
      this.write(next);
      const applied = this.snapshot();
      if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) {
        throw new Error('The Banner recovery payload could not be applied.');
      }
      this.baseline = applied;
    } catch (error) {
      try {
        this.write(previous);
        this.baseline = previous;
      } catch (rollbackError) {
        Object.defineProperty(error, 'rollbackError', { value: rollbackError });
      }
      throw error;
    } finally {
      this.suppress -= 1;
    }
  }

  snapshot() {
    const payload = Object.fromEntries(ORDINARY_FIELD_KEYS.map((key) => [key, this.fields[key].value]));
    payload.description = this.editor.getValue();
    payload.type = parseInteger(this.fields.type.value, 'type');
    const ownPrefix = this.fields.own_prefix.find((control) => control.checked);
    if (!ownPrefix) throw new TypeError('The Banner own-prefix field is invalid.');
    payload.own_prefix = parseInteger(ownPrefix.value, 'own_prefix');
    payload.catid = parseInteger(this.fields.catid.value, 'catid');
    // An empty client control means "no client", the same state the native
    // form stores as cid 0.
    payload.cid = parseInteger(this.fields.cid.value === '' ? '0' : this.fields.cid.value, 'cid');
    payload.imageurl = this.fields.imageurl.value;
    DATE_KEYS.forEach((key) => {
      payload[key] = this.fields[key].value;
      payload[`${key}_alt`] = this.fields[key].getAttribute('data-alt-value') || '';
    });
    return validatePayload(payload);
  }

  write(payload) {
    ORDINARY_FIELD_KEYS.forEach((key) => this.writeControl(this.fields[key], payload[key]));
    this.editor.setValue(payload.description);
    this.writeControl(this.fields.type, String(payload.type));
    this.writeControl(this.fields.catid, String(payload.catid));
    this.writeControl(this.fields.cid, String(payload.cid));
    this.fields.own_prefix.forEach((control) => {
      control.checked = control.value === String(payload.own_prefix);
      control.dispatchEvent(this.eventFactory('input'));
      control.dispatchEvent(this.eventFactory('change'));
    });
    DATE_KEYS.forEach((key) => {
      this.fields[key].setAttribute('data-alt-value', payload[`${key}_alt`]);
      this.writeControl(this.fields[key], payload[key]);
    });
    this.mediaField.setValue(payload.imageurl);
  }

  writeControl(control, value) {
    control.value = value;
    control.dispatchEvent(this.eventFactory('input'));
    control.dispatchEvent(this.eventFactory('change'));
  }

  observableKeys() { return [...ORDINARY_FIELD_KEYS, ...DATE_KEYS, 'imageurl', 'type', 'own_prefix', 'catid', 'cid']; }

  controls(key) { return key === 'own_prefix' ? this.fields[key] : [this.fields[key]]; }

  changed() {
    if (this.destroyed || this.suppress || !this.callback || !this.isCurrent()) return;
    let current;
    try { current = this.snapshot(); } catch { return; }
    if (PAYLOAD_KEYS.every((key) => current[key] === this.baseline?.[key])) return;
    this.baseline = current;
    this.callback();
  }

  isCurrent() {
    return !this.destroyed && this.form?.isConnected && this.fields
      && [...ORDINARY_FIELD_KEYS, ...DATE_KEYS, 'description', 'imageurl', 'type', 'own_prefix', 'catid', 'cid']
        .every((key) => this.controls(key).every((control) => control?.isConnected && this.form.contains(control)))
      && this.mediaField.isConnected && this.mediaField.contains(this.fields.imageurl)
      && this.getCurrentEditor(this.editorId) === this.editor;
  }

  assertCurrent() { if (!this.isCurrent()) throw new Error('The Banner form dependencies are stale.'); }

  unsubscribe() {
    this.observableKeys().forEach((key) => this.controls(key).forEach((control) => {
      control.removeEventListener('input', this.listeners[key]);
      control.removeEventListener('change', this.listeners[key]);
    }));
    this.callback = null;
    const unsubscribe = this.unsubscribeEditor;
    this.unsubscribeEditor = null;
    unsubscribe?.();
  }

  destroy() {
    if (this.destroyed) return;
    this.unsubscribe();
    this.destroyed = true;
    this.form = null;
    this.fields = null;
    this.editor = null;
    this.getCurrentEditor = null;
    this.mediaField = null;
  }
}

export {
  DATE_KEYS,
  INTEGER_KEYS,
  MAXIMUM_LENGTHS,
  PAYLOAD_KEYS,
  STRING_KEYS,
  isStableMediaReference,
  normalizeAutosaveTarget,
  normalizeCanonicalId,
  validatePayload,
};
