/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { AutosaveApiClient, AutosaveRuntime } from 'com_autosave.runtime';
import AutosaveCanonicalActionCoordinator from 'com_autosave.canonical-actions';
import createAutosavePresenter from 'com_autosave.ui';
import { JoomlaEditor } from 'editor-api';
import ArticleAutosaveAdapter, { normalizeCanonicalId } from './article-autosave-adapter.es6.js';

const ARTICLE_OPTIONS_KEY = 'com_content.autosave.article';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const OPERATIONS = Object.freeze([
  'initialize',
  'preserve',
  'detect',
  'read',
  'discard',
  'prepareCanonicalAction',
  'getCanonicalActionOutcome',
]);
const FIELD_KEYS = Object.freeze(['title', 'alias', 'articletext', 'catid']);
const ARTICLE_CANONICAL_TASK_POLICY = Object.freeze({
  'article.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'article.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'article.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'article.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'article.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

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
    locale: typeof configuration.locale === 'string' ? configuration.locale : '',
    timeZone: typeof configuration.timeZone === 'string' ? configuration.timeZone : '',
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

const sameRuntimeResolution = (pair, resolution) => pair
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

const samePresentationConfiguration = (first, second) => first.locale === second.locale
  && first.timeZone === second.timeZone;

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
    eventTargetFactory = () => new EventTarget(),
    presenterFactory = createAutosavePresenter,
    coordinatorFactory = (options) => new AutosaveCanonicalActionCoordinator(options),
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
      || typeof runtimeFactory !== 'function'
      || typeof eventTargetFactory !== 'function'
      || typeof presenterFactory !== 'function'
      || typeof coordinatorFactory !== 'function') {
      throw new TypeError('The Article Autosave controller configuration is invalid.');
    }

    this.document = documentSource;
    this.optionsReader = optionsReader;
    this.editorRegistry = editorRegistry;
    this.adapterFactory = adapterFactory;
    this.apiClientFactory = apiClientFactory;
    this.runtimeFactory = runtimeFactory;
    this.eventTargetFactory = eventTargetFactory;
    this.presenterFactory = presenterFactory;
    this.coordinatorFactory = coordinatorFactory;
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

    if (sameRuntimeResolution(this.activePair, resolution)) {
      this.reconcilePresenter(this.activePair, resolution);

      return true;
    }

    if (sameRuntimeResolution(this.pendingPair, resolution)) {
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
    let eventTarget;
    let coordinator;

    try {
      eventTarget = this.eventTargetFactory();

      if (!eventTarget
        || typeof eventTarget.addEventListener !== 'function'
        || typeof eventTarget.removeEventListener !== 'function'
        || typeof eventTarget.dispatchEvent !== 'function') {
        throw new TypeError('The Article Autosave event target is invalid.');
      }

      runtime = this.runtimeFactory({
        apiClient,
        adapter,
        context: resolution.article.context,
        targetId: resolution.article.targetId,
        schemaVersion: resolution.article.payloadSchemaVersion,
        eventTarget,
      });
      coordinator = this.coordinatorFactory({
        form: resolution.form,
        runtime,
        taskPolicy: ARTICLE_CANONICAL_TASK_POLICY,
      });

      if (!coordinator || typeof coordinator.start !== 'function' || typeof coordinator.destroy !== 'function') {
        throw new TypeError('The Article Autosave canonical action coordinator is invalid.');
      }
    } catch (error) {
      coordinator?.destroy?.();

      if (runtime) {
        runtime.destroy();
      } else {
        adapter.destroy();
      }

      return false;
    }

    const pair = {
      generation,
      runtime,
      coordinator,
      adapter,
      eventTarget,
      presenter: null,
      presenterGeneration: 0,
      statusMount: null,
      recoveryMount: null,
      presentationConfiguration: null,
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

      coordinator.start();
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
      || !sameRuntimeResolution(pair, current)) {
      if (this.pendingPair === pair) {
        this.pendingPair = null;
      }

      runtime.destroy();

      return false;
    }

    this.pendingPair = null;
    this.activePair = pair;
    this.reconcilePresenter(pair, current);

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
      statusMount: this.resolveUiMount(form, '[data-joomla-autosave-status-ui]'),
      recoveryMount: this.resolveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
      presentationConfiguration: {
        locale: typeof article.locale === 'string' ? article.locale : '',
        timeZone: typeof article.timeZone === 'string' ? article.timeZone : '',
      },
    };
  }

  resolveUiMount(form, selector) {
    if (typeof form.querySelectorAll !== 'function') {
      return null;
    }

    const mounts = form.querySelectorAll(selector);

    if (mounts.length !== 1
      || !mounts[0].isConnected
      || !form.contains(mounts[0])) {
      return null;
    }

    return mounts[0];
  }

  reconcilePresenter(pair, resolution) {
    const statusMount = resolution.statusMount;
    const recoveryMount = resolution.recoveryMount;
    const configuration = resolution.presentationConfiguration;
    const sameMounts = pair.statusMount === statusMount
      && pair.recoveryMount === recoveryMount;
    const sameConfiguration = pair.presentationConfiguration
      && samePresentationConfiguration(pair.presentationConfiguration, configuration);
    const hasMount = statusMount || recoveryMount;

    if (sameMounts && sameConfiguration && (pair.presenter || !hasMount)) {
      return Boolean(pair.presenter);
    }

    if (!sameMounts || !sameConfiguration) {
      pair.presenterGeneration += 1;
      pair.presenter?.destroy();
      pair.presenter = null;
      pair.statusMount = statusMount;
      pair.recoveryMount = recoveryMount;
      pair.presentationConfiguration = { ...configuration };
    }

    if (!hasMount) {
      return false;
    }

    let presenter;

    try {
      presenter = this.presenterFactory({
        runtime: pair.runtime,
        eventTarget: pair.eventTarget,
        statusMount,
        recoveryMount,
        locale: configuration.locale,
        timeZone: configuration.timeZone,
      });
    } catch (error) {
      presenter = null;
    }

    if (!presenter || typeof presenter.destroy !== 'function') {
      presenter?.destroy?.();

      return false;
    }

    pair.presenter = presenter;

    return true;
  }

  destroyPair(pair) {
    pair.presenterGeneration += 1;
    pair.presenter?.destroy();
    pair.presenter = null;
    pair.coordinator.destroy();
    pair.runtime.destroy();
  }

  invalidatePairs() {
    this.generation += 1;

    if (this.pendingPair) {
      const pending = this.pendingPair;
      this.pendingPair = null;
      this.destroyPair(pending);
    }

    if (this.activePair) {
      const active = this.activePair;
      this.activePair = null;
      this.destroyPair(active);
    }

    return this.generation;
  }
}

export {
  ARTICLE_CANONICAL_TASK_POLICY,
  ARTICLE_OPTIONS_KEY,
  RUNTIME_OPTIONS_KEY,
  validateArticleConfiguration,
  validateRuntimeConfiguration,
};
