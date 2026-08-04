/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { AutosaveApiClient, AutosaveRuntime } from 'com_autosave.runtime';
import { JoomlaEditor } from 'editor-api';
import ArticleAutosaveAdapter, { normalizeCanonicalId } from './article-autosave-adapter.es6.js';

const ARTICLE_OPTIONS_KEY = 'com_content.autosave.article';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const OPERATIONS = Object.freeze(['initialize', 'preserve', 'detect', 'read', 'discard']);
const FIELD_KEYS = Object.freeze(['title', 'alias', 'articletext', 'catid']);

const isPlainObject = (value) => value !== null
  && typeof value === 'object'
  && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;

const defaultOptionsReader = (key, fallback) => {
  globalThis.Joomla?.loadOptions?.();

  return globalThis.Joomla?.getOptions?.(key, fallback) ?? fallback;
};

const validateArticleConfiguration = (configuration) => {
  if (!isPlainObject(configuration) || configuration.enabled !== true) {
    return null;
  }

  if (typeof configuration.context !== 'string'
    || configuration.context.length === 0
    || !Number.isInteger(configuration.payloadSchemaVersion)
    || configuration.payloadSchemaVersion <= 0
    || typeof configuration.formId !== 'string'
    || configuration.formId.length === 0
    || !isPlainObject(configuration.fieldIds)
    || !FIELD_KEYS.every(
      (key) => typeof configuration.fieldIds[key] === 'string'
        && configuration.fieldIds[key].length > 0,
    )) {
    throw new TypeError('The Article Autosave page configuration is invalid.');
  }

  return Object.freeze({
    context: configuration.context,
    targetId: normalizeCanonicalId(configuration.targetId, 'target'),
    payloadSchemaVersion: configuration.payloadSchemaVersion,
    formId: configuration.formId,
    fieldIds: Object.freeze({ ...configuration.fieldIds }),
  });
};

const validateRuntimeConfiguration = (configuration, csrf) => {
  if (!isPlainObject(configuration)
    || !isPlainObject(configuration.endpoints)
    || !OPERATIONS.every(
      (operation) => typeof configuration.endpoints[operation] === 'string'
        && configuration.endpoints[operation].length > 0,
    )
    || typeof csrf !== 'string'
    || csrf.length === 0) {
    throw new TypeError('The Article Autosave runtime configuration is invalid.');
  }

  return Object.freeze({
    endpoints: Object.freeze(
      Object.fromEntries(OPERATIONS.map((operation) => [
        operation,
        configuration.endpoints[operation],
      ])),
    ),
    csrf,
  });
};

const sameEndpoints = (first, second) => OPERATIONS.every(
  (operation) => first[operation] === second[operation],
);

const sameResolution = (pair, resolution) => pair
  && resolution
  && pair.form === resolution.form
  && pair.editor === resolution.editor
  && pair.context === resolution.article.context
  && pair.targetId === resolution.article.targetId
  && pair.payloadSchemaVersion === resolution.article.payloadSchemaVersion
  && pair.editorId === resolution.article.fieldIds.articletext
  && pair.runtimeConfiguration.csrf === resolution.runtime.csrf
  && sameEndpoints(pair.runtimeConfiguration.endpoints, resolution.runtime.endpoints)
  && FIELD_KEYS.every((key) => pair.fields[key] === resolution.fields[key]);

/**
 * Own one Article adapter/runtime pair for the current administrator document.
 */
export default class ArticleAutosaveController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    editorRegistry = JoomlaEditor,
    adapterFactory = (options) => new ArticleAutosaveAdapter(options),
    apiClientFactory = (options) => new AutosaveApiClient(options),
    runtimeFactory = (options) => new AutosaveRuntime(options),
  } = {}) {
    if (!documentSource
      || typeof documentSource.addEventListener !== 'function'
      || typeof documentSource.removeEventListener !== 'function'
      || typeof documentSource.getElementById !== 'function'
      || typeof optionsReader !== 'function'
      || !editorRegistry
      || typeof editorRegistry.get !== 'function'
      || typeof editorRegistry.subscribeLifecycle !== 'function'
      || typeof adapterFactory !== 'function'
      || typeof apiClientFactory !== 'function'
      || typeof runtimeFactory !== 'function') {
      throw new TypeError('The Article Autosave controller configuration is invalid.');
    }

    this.document = documentSource;
    this.optionsReader = optionsReader;
    this.editorRegistry = editorRegistry;
    this.adapterFactory = adapterFactory;
    this.apiClientFactory = apiClientFactory;
    this.runtimeFactory = runtimeFactory;
    this.started = false;
    this.destroyed = false;
    this.generation = 0;
    this.activePair = null;
    this.pendingPair = null;
    this.unsubscribeLifecycle = null;

    this.handleUpdated = () => {
      void this.reconcile();
    };
    this.handleEditorLifecycle = (detail) => {
      const configuration = this.optionsReader(ARTICLE_OPTIONS_KEY, null);
      const editorId = configuration?.fieldIds?.articletext;

      if (detail?.id === editorId) {
        void this.reconcile();
      }
    };
  }

  start() {
    if (this.started || this.destroyed) {
      return this;
    }

    this.started = true;
    this.document.addEventListener('joomla:updated', this.handleUpdated);
    this.unsubscribeLifecycle = this.editorRegistry.subscribeLifecycle(
      this.handleEditorLifecycle,
    );
    void this.reconcile();

    return this;
  }

  async reconcile() {
    if (!this.started || this.destroyed) {
      return false;
    }

    let resolution;

    try {
      resolution = this.resolve();
    } catch (error) {
      resolution = null;
    }

    if (sameResolution(this.activePair, resolution)
      || sameResolution(this.pendingPair, resolution)) {
      return true;
    }

    const generation = this.invalidatePairs();

    if (!resolution) {
      return false;
    }

    let adapter;

    try {
      adapter = this.adapterFactory({
        descriptor: {
          context: resolution.article.context,
          targetId: resolution.article.targetId,
          payloadSchemaVersion: resolution.article.payloadSchemaVersion,
        },
        form: resolution.form,
        fields: resolution.fields,
        editor: resolution.editor,
        getCurrentEditor: (id) => this.editorRegistry.get(id),
      });
      adapter.initializeBaseline();
    } catch (error) {
      adapter?.destroy?.();

      return false;
    }

    let apiClient;

    try {
      apiClient = this.apiClientFactory({
        endpoints: resolution.runtime.endpoints,
        csrf: resolution.runtime.csrf,
      });
    } catch (error) {
      adapter.destroy();

      return false;
    }

    let runtime;

    try {
      runtime = this.runtimeFactory({
        apiClient,
        adapter,
        context: resolution.article.context,
        targetId: resolution.article.targetId,
        schemaVersion: resolution.article.payloadSchemaVersion,
        eventTarget: this.document,
      });
    } catch (error) {
      adapter.destroy();

      return false;
    }

    const pair = {
      generation,
      runtime,
      adapter,
      form: resolution.form,
      fields: resolution.fields,
      editor: resolution.editor,
      editorId: resolution.article.fieldIds.articletext,
      context: resolution.article.context,
      targetId: resolution.article.targetId,
      payloadSchemaVersion: resolution.article.payloadSchemaVersion,
      runtimeConfiguration: resolution.runtime,
    };
    this.pendingPair = pair;

    try {
      const started = runtime.start();

      if (started && typeof started.then === 'function') {
        await started;
      }
    } catch (error) {
      if (this.pendingPair === pair) {
        this.pendingPair = null;
      }

      runtime.destroy();

      return false;
    }

    let current;

    try {
      current = this.resolve();
    } catch (error) {
      current = null;
    }

    if (this.destroyed
      || generation !== this.generation
      || this.pendingPair !== pair
      || !sameResolution(pair, current)) {
      if (this.pendingPair === pair) {
        this.pendingPair = null;
      }

      runtime.destroy();

      return false;
    }

    this.pendingPair = null;
    this.activePair = pair;

    return true;
  }

  destroy() {
    if (this.destroyed) {
      return;
    }

    this.destroyed = true;
    this.started = false;
    this.invalidatePairs();
    this.document.removeEventListener('joomla:updated', this.handleUpdated);

    if (this.unsubscribeLifecycle) {
      const unsubscribe = this.unsubscribeLifecycle;
      this.unsubscribeLifecycle = null;
      unsubscribe();
    }

    this.document = null;
  }

  resolve() {
    const article = validateArticleConfiguration(
      this.optionsReader(ARTICLE_OPTIONS_KEY, null),
    );

    if (!article) {
      return null;
    }

    const runtime = validateRuntimeConfiguration(
      this.optionsReader(RUNTIME_OPTIONS_KEY, null),
      this.optionsReader('csrf.token', ''),
    );
    const form = this.document.getElementById(article.formId);

    if (!form?.isConnected) {
      return null;
    }

    const fields = Object.fromEntries(FIELD_KEYS.map((key) => [
      key,
      this.document.getElementById(article.fieldIds[key]),
    ]));

    if (!FIELD_KEYS.every(
      (key) => fields[key]?.isConnected && form.contains(fields[key]),
    )) {
      return null;
    }

    const editor = this.editorRegistry.get(article.fieldIds.articletext);

    if (!editor
      || typeof editor.supportsChangeObservation !== 'function'
      || editor.supportsChangeObservation() !== true
      || typeof editor.subscribeChange !== 'function'
      || typeof editor.getValue !== 'function'
      || typeof editor.setValue !== 'function') {
      return null;
    }

    return {
      article,
      runtime,
      form,
      fields,
      editor,
    };
  }

  invalidatePairs() {
    this.generation += 1;

    if (this.pendingPair) {
      const pending = this.pendingPair;
      this.pendingPair = null;
      pending.runtime.destroy();
    }

    if (this.activePair) {
      const active = this.activePair;
      this.activePair = null;
      active.runtime.destroy();
    }

    return this.generation;
  }
}

export {
  ARTICLE_OPTIONS_KEY,
  RUNTIME_OPTIONS_KEY,
  validateArticleConfiguration,
  validateRuntimeConfiguration,
};
