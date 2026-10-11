/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const STRING_KEYS = Object.freeze(['title', 'uid', 'description', 'note', 'url']);
const PAYLOAD_KEYS = Object.freeze([...STRING_KEYS, 'autostart']);
const TEXT_CONTROL_KEYS = Object.freeze(['title', 'uid', 'note', 'url']);
const MAXIMUM_ID = 4294967295;
const MAXIMUM_LENGTHS = Object.freeze({
  title: 255,
  uid: 255,
  description: 65535,
  note: 255,
  url: 255,
});

const isPlainObject = (value) => value !== null && typeof value === 'object'
  && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;

const normalizeCanonicalId = (value) => {
  const candidate = typeof value === 'number' && Number.isSafeInteger(value) ? String(value) : value;

  if (typeof candidate !== 'string'
    || !/^[1-9][0-9]{0,9}$/.test(candidate)
    || Number(candidate) > MAXIMUM_ID) {
    throw new TypeError('The Guided Tour Autosave target is invalid.');
  }

  return candidate;
};
const normalizeAutosaveTarget = (value) => (value === null
  || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value))
  ? value : normalizeCanonicalId(value));

const parseAutostart = (value) => {
  if (value !== '0' && value !== '1') {
    throw new TypeError('The Guided Tour Autosave autostart field is invalid.');
  }

  return Number(value);
};

const validatePayload = (payload) => {
  if (!isPlainObject(payload)
    || Object.keys(payload).length !== PAYLOAD_KEYS.length
    || !PAYLOAD_KEYS.every((key) => Object.hasOwn(payload, key))
    || !STRING_KEYS.every((key) => typeof payload[key] === 'string'
      && Array.from(payload[key]).length <= MAXIMUM_LENGTHS[key])
    || !Number.isInteger(payload.autostart)
    || ![0, 1].includes(payload.autostart)) {
    throw new TypeError('The Guided Tour Autosave recovery payload is invalid.');
  }

  return Object.fromEntries(PAYLOAD_KEYS.map((key) => [key, payload[key]]));
};

const reportCallbackError = (error) => {
  if (typeof globalThis.reportError === 'function') globalThis.reportError(error);
  else queueMicrotask(() => { throw error; });
};

export default class TourAutosaveAdapter {
  constructor({
    descriptor,
    form,
    fields,
    editor,
    getCurrentEditor,
    eventFactory = (type) => new Event(type, { bubbles: true }),
  }) {
    if (!isPlainObject(descriptor)
      || typeof descriptor.context !== 'string' || !descriptor.context
      || !Number.isInteger(descriptor.payloadSchemaVersion) || descriptor.payloadSchemaVersion <= 0
      || !form || !isPlainObject(fields)
      || !PAYLOAD_KEYS.every((key) => fields[key])
      || !editor || typeof editor.getValue !== 'function' || typeof editor.setValue !== 'function'
      || typeof editor.subscribeChange !== 'function' || typeof getCurrentEditor !== 'function'
      || typeof eventFactory !== 'function') {
      throw new TypeError('The Guided Tour Autosave adapter configuration is invalid.');
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
    this.eventFactory = eventFactory;
    this.baseline = null;
    this.callback = null;
    this.unsubscribeEditor = null;
    this.subscribed = false;
    this.destroyed = false;
    this.suppressChanges = 0;
    this.listeners = Object.fromEntries(
      [...TEXT_CONTROL_KEYS, 'autostart'].map((key) => [key, () => this.handleChange()]),
    );
    this.editorListener = () => this.handleChange();
  }

  getDescriptor() { return this.descriptor; }

  initializeBaseline() {
    this.assertCurrent();
    this.baseline = this.readSnapshot();
    return this;
  }

  subscribe(callback) {
    if (typeof callback !== 'function') {
      throw new TypeError('The Guided Tour Autosave change callback must be a function.');
    }
    if (this.destroyed || this.subscribed) {
      throw new Error('The Guided Tour Autosave adapter cannot be subscribed.');
    }
    if (!this.baseline) this.initializeBaseline();

    this.subscribed = true;
    this.callback = callback;
    [...TEXT_CONTROL_KEYS, 'autostart'].forEach((key) => this.controls(key).forEach((control) => {
      control.addEventListener('input', this.listeners[key]);
      control.addEventListener('change', this.listeners[key]);
    }));

    try {
      this.unsubscribeEditor = this.editor.subscribeChange(this.editorListener);
    } catch (error) {
      this.removeListeners();
      this.subscribed = false;
      this.callback = null;
      throw error;
    }

    if (typeof this.unsubscribeEditor !== 'function') {
      this.removeListeners();
      this.subscribed = false;
      this.callback = null;
      throw new TypeError('The Guided Tour editor change subscription is invalid.');
    }

    let active = true;
    return () => {
      if (active) {
        active = false;
        this.unsubscribe();
      }
    };
  }

  capture() {
    this.assertCurrent();
    const snapshot = this.readSnapshot();
    this.baseline = snapshot;
    return { ...snapshot };
  }

  apply(payload) {
    if (this.destroyed) throw new Error('The Guided Tour Autosave adapter has been destroyed.');
    const next = validatePayload(payload);
    this.assertCurrent();
    const previous = this.readSnapshot();
    this.suppressChanges += 1;

    try {
      this.applySnapshot(next);
      const applied = this.readSnapshot();
      if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) {
        throw new Error('The Guided Tour Autosave recovery payload could not be applied.');
      }
      this.baseline = applied;
    } catch (error) {
      try {
        this.applySnapshot(previous);
        this.baseline = previous;
      } catch (rollbackError) {
        Object.defineProperty(error, 'rollbackError', { value: rollbackError });
      }
      throw error;
    } finally {
      this.suppressChanges -= 1;
    }
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.unsubscribe();
    this.baseline = null;
    this.form = null;
    this.fields = null;
    this.editor = null;
    this.getCurrentEditor = null;
    this.listeners = null;
  }

  controls(key) { return key === 'autostart' ? this.fields.autostart : [this.fields[key]]; }

  readSnapshot() {
    const selectedAutostart = this.fields.autostart.find((control) => control.checked);
    const description = this.editor.getValue();
    const snapshot = {
      title: this.fields.title.value,
      uid: this.fields.uid.value,
      description,
      note: this.fields.note.value,
      url: this.fields.url.value,
      autostart: parseAutostart(selectedAutostart?.value),
    };

    return validatePayload(snapshot);
  }

  applySnapshot(snapshot) {
    TEXT_CONTROL_KEYS.forEach((key) => {
      this.fields[key].value = snapshot[key];
      this.fields[key].dispatchEvent(this.eventFactory('input'));
      this.fields[key].dispatchEvent(this.eventFactory('change'));
    });
    this.editor.setValue(snapshot.description);
    this.fields.autostart.forEach((control) => {
      control.checked = control.value === String(snapshot.autostart);
      control.dispatchEvent(this.eventFactory('input'));
      control.dispatchEvent(this.eventFactory('change'));
    });
  }

  handleChange() {
    if (this.destroyed || this.suppressChanges || !this.callback || !this.isCurrent()) return;
    let snapshot;
    try { snapshot = this.readSnapshot(); } catch { return; }
    if (PAYLOAD_KEYS.every((key) => snapshot[key] === this.baseline?.[key])) return;
    this.baseline = snapshot;
    try { this.callback(); } catch (error) { reportCallbackError(error); }
  }

  isCurrent() {
    return !this.destroyed && this.form?.isConnected && this.fields
      && PAYLOAD_KEYS.every((key) => this.controls(key).length > 0
        && this.controls(key).every((control) => control?.isConnected && this.form.contains(control)))
      && this.getCurrentEditor(this.editorId) === this.editor;
  }

  assertCurrent() {
    if (!this.isCurrent()) throw new Error('The Guided Tour Autosave form or editor is no longer current.');
  }

  unsubscribe() {
    if (!this.subscribed && !this.unsubscribeEditor) return;
    this.removeListeners();
    this.subscribed = false;
    this.callback = null;
    if (this.unsubscribeEditor) {
      const unsubscribe = this.unsubscribeEditor;
      this.unsubscribeEditor = null;
      unsubscribe();
    }
  }

  removeListeners() {
    if (!this.fields || !this.listeners) return;
    [...TEXT_CONTROL_KEYS, 'autostart'].forEach((key) => this.controls(key).forEach((control) => {
      control.removeEventListener('input', this.listeners[key]);
      control.removeEventListener('change', this.listeners[key]);
    }));
  }
}

export {
  MAXIMUM_LENGTHS,
  PAYLOAD_KEYS,
  STRING_KEYS,
  normalizeCanonicalId,
  validatePayload,
};
