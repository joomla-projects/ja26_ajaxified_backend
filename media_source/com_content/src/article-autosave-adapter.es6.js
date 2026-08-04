/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const PAYLOAD_KEYS = Object.freeze(['title', 'alias', 'articletext', 'catid']);
const MAXIMUM_ID = 4294967295;

const isPlainObject = (value) => value !== null
  && typeof value === 'object'
  && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;

const normalizeCanonicalId = (value, fieldName) => {
  const candidate = typeof value === 'number' && Number.isSafeInteger(value)
    ? String(value)
    : value;

  if (typeof candidate !== 'string'
    || !/^[1-9][0-9]{0,9}$/.test(candidate)
    || Number(candidate) > MAXIMUM_ID) {
    throw new TypeError(`The Article Autosave ${fieldName} is invalid.`);
  }

  return candidate;
};

const validatePayload = (payload) => {
  if (!isPlainObject(payload)) {
    throw new TypeError('The Article Autosave recovery payload is invalid.');
  }

  const keys = Object.keys(payload).sort();
  const expected = [...PAYLOAD_KEYS].sort();

  if (keys.length !== expected.length
    || !keys.every((key, index) => key === expected[index])
    || typeof payload.title !== 'string'
    || typeof payload.alias !== 'string'
    || typeof payload.articletext !== 'string'
    || !Number.isSafeInteger(payload.catid)) {
    throw new TypeError('The Article Autosave recovery payload is invalid.');
  }

  return {
    title: payload.title,
    alias: payload.alias,
    articletext: payload.articletext,
    catid: Number(normalizeCanonicalId(payload.catid, 'category')),
  };
};

const reportCallbackError = (error) => {
  if (typeof globalThis.reportError === 'function') {
    globalThis.reportError(error);

    return;
  }

  queueMicrotask(() => {
    throw error;
  });
};

/**
 * Component-owned adapter between an Article edit form and the generic Autosave runtime.
 */
export default class ArticleAutosaveAdapter {
  constructor({
    descriptor,
    form,
    fields,
    editor,
    getCurrentEditor,
    eventFactory = (type) => new Event(type, { bubbles: true }),
  }) {
    if (!isPlainObject(descriptor)
      || typeof descriptor.context !== 'string'
      || descriptor.context.length === 0
      || !Number.isInteger(descriptor.payloadSchemaVersion)
      || descriptor.payloadSchemaVersion <= 0
      || !form
      || !isPlainObject(fields)
      || !PAYLOAD_KEYS.every((key) => fields[key])
      || !editor
      || typeof editor.getValue !== 'function'
      || typeof editor.setValue !== 'function'
      || typeof editor.subscribeChange !== 'function'
      || typeof getCurrentEditor !== 'function'
      || typeof eventFactory !== 'function') {
      throw new TypeError('The Article Autosave adapter configuration is invalid.');
    }

    this.descriptor = Object.freeze({
      context: descriptor.context,
      targetId: normalizeCanonicalId(descriptor.targetId, 'target'),
      payloadSchemaVersion: descriptor.payloadSchemaVersion,
    });
    this.form = form;
    this.fields = { ...fields };
    this.editor = editor;
    this.editorId = fields.articletext.id;
    this.getCurrentEditor = getCurrentEditor;
    this.eventFactory = eventFactory;
    this.baseline = null;
    this.onMeaningfulChange = null;
    this.unsubscribeEditor = null;
    this.subscribed = false;
    this.destroyed = false;
    this.suppressChanges = 0;

    this.handleTitleChange = () => this.handleFieldChange('title');
    this.handleAliasChange = () => this.handleFieldChange('alias');
    this.handleCategoryChange = () => this.handleFieldChange('catid');
    this.handleEditorChange = () => this.handleFieldChange('articletext');
  }

  getDescriptor() {
    return this.descriptor;
  }

  initializeBaseline() {
    this.assertCurrent();
    this.baseline = this.readSnapshot();

    return this;
  }

  subscribe(onMeaningfulChange) {
    if (typeof onMeaningfulChange !== 'function') {
      throw new TypeError('The Article Autosave change callback must be a function.');
    }

    if (this.destroyed || this.subscribed) {
      throw new Error('The Article Autosave adapter cannot be subscribed.');
    }

    if (!this.baseline) {
      this.initializeBaseline();
    }

    this.subscribed = true;
    this.onMeaningfulChange = onMeaningfulChange;
    this.fields.title.addEventListener('input', this.handleTitleChange);
    this.fields.title.addEventListener('change', this.handleTitleChange);
    this.fields.alias.addEventListener('input', this.handleAliasChange);
    this.fields.alias.addEventListener('change', this.handleAliasChange);
    this.fields.catid.addEventListener('change', this.handleCategoryChange);

    try {
      this.unsubscribeEditor = this.editor.subscribeChange(this.handleEditorChange);
    } catch (error) {
      this.removeListeners();
      this.subscribed = false;
      this.onMeaningfulChange = null;
      throw error;
    }

    if (typeof this.unsubscribeEditor !== 'function') {
      this.removeListeners();
      this.subscribed = false;
      this.onMeaningfulChange = null;
      throw new TypeError('The Article editor change subscription is invalid.');
    }

    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.unsubscribe();
    };
  }

  capture() {
    this.assertCurrent();
    const snapshot = this.readSnapshot();
    this.baseline = snapshot;

    return { ...snapshot };
  }

  apply(payload) {
    if (this.destroyed) {
      throw new Error('The Article Autosave adapter has been destroyed.');
    }

    const next = validatePayload(payload);
    this.assertCurrent();

    if (!this.categoryExists(next.catid)) {
      throw new TypeError('The Article Autosave category is unavailable.');
    }

    const previous = this.readSnapshot();
    this.suppressChanges += 1;

    try {
      this.applySnapshot(next);

      const applied = this.readSnapshot();

      if (!PAYLOAD_KEYS.every((key) => applied[key] === next[key])) {
        throw new Error('The Article Autosave recovery payload could not be applied.');
      }

      this.baseline = applied;
    } catch (error) {
      try {
        this.applySnapshot(previous);
        this.baseline = previous;
      } catch (rollbackError) {
        Object.defineProperty(error, 'rollbackError', {
          configurable: true,
          value: rollbackError,
        });
      }

      throw error;
    } finally {
      this.suppressChanges -= 1;
    }
  }

  destroy() {
    if (this.destroyed) {
      return;
    }

    this.destroyed = true;
    this.unsubscribe();
    this.baseline = null;
    this.form = null;
    this.fields = null;
    this.editor = null;
    this.getCurrentEditor = null;
  }

  handleFieldChange(field) {
    if (this.destroyed || this.suppressChanges > 0 || !this.onMeaningfulChange) {
      return;
    }

    if (!this.isCurrent()) {
      return;
    }

    let value;

    try {
      value = this.readValue(field);
    } catch (error) {
      value = null;
    }

    if (this.baseline?.[field] === value) {
      return;
    }

    this.baseline = { ...this.baseline, [field]: value };

    try {
      this.onMeaningfulChange();
    } catch (error) {
      reportCallbackError(error);
    }
  }

  readSnapshot() {
    return {
      title: this.readValue('title'),
      alias: this.readValue('alias'),
      articletext: this.readValue('articletext'),
      catid: this.readValue('catid'),
    };
  }

  readValue(field) {
    switch (field) {
      case 'title':
      case 'alias':
        if (typeof this.fields[field].value !== 'string') {
          throw new TypeError(`The Article Autosave ${field} field is invalid.`);
        }

        return this.fields[field].value;

      case 'articletext': {
        const value = this.editor.getValue();

        if (typeof value !== 'string') {
          throw new TypeError('The Article Autosave articletext field is invalid.');
        }

        return value;
      }

      case 'catid':
        return Number(normalizeCanonicalId(this.fields.catid.value, 'category'));

      default:
        throw new TypeError('The Article Autosave field is unsupported.');
    }
  }

  applySnapshot(snapshot) {
    this.fields.title.value = snapshot.title;
    this.fields.title.dispatchEvent(this.eventFactory('input'));
    this.fields.title.dispatchEvent(this.eventFactory('change'));
    this.fields.alias.value = snapshot.alias;
    this.fields.alias.dispatchEvent(this.eventFactory('input'));
    this.fields.alias.dispatchEvent(this.eventFactory('change'));
    this.fields.catid.value = String(snapshot.catid);
    this.fields.catid.dispatchEvent(this.eventFactory('change'));
    this.editor.setValue(snapshot.articletext);
  }

  categoryExists(categoryId) {
    return this.fields.catid.options
      && Array.from(this.fields.catid.options).some(
        (option) => option.value === String(categoryId),
      );
  }

  isCurrent() {
    if (this.destroyed
      || !this.form?.isConnected
      || !this.fields
      || !PAYLOAD_KEYS.every((key) => this.fields[key]?.isConnected)
      || !PAYLOAD_KEYS.every((key) => this.form.contains(this.fields[key]))) {
      return false;
    }

    return this.getCurrentEditor(this.editorId) === this.editor;
  }

  assertCurrent() {
    if (!this.isCurrent()) {
      throw new Error('The Article Autosave form or editor is no longer current.');
    }
  }

  unsubscribe() {
    if (!this.subscribed && !this.unsubscribeEditor) {
      return;
    }

    this.removeListeners();
    this.subscribed = false;
    this.onMeaningfulChange = null;

    if (this.unsubscribeEditor) {
      const unsubscribe = this.unsubscribeEditor;
      this.unsubscribeEditor = null;
      unsubscribe();
    }
  }

  removeListeners() {
    if (!this.fields) {
      return;
    }

    this.fields.title.removeEventListener('input', this.handleTitleChange);
    this.fields.title.removeEventListener('change', this.handleTitleChange);
    this.fields.alias.removeEventListener('input', this.handleAliasChange);
    this.fields.alias.removeEventListener('change', this.handleAliasChange);
    this.fields.catid.removeEventListener('change', this.handleCategoryChange);
  }
}

export { normalizeCanonicalId, validatePayload };
