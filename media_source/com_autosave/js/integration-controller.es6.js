/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { AutosaveApiClient, AutosaveRuntime } from 'com_autosave.runtime';
import AutosaveCanonicalActionCoordinator from 'com_autosave.canonical-actions';
import createAutosavePresenter from 'com_autosave.ui';

const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const OPERATIONS = Object.freeze([
  'initialize',
  'initializeCreate',
  'preserve',
  'detect',
  'read',
  'discard',
  'prepareCanonicalAction',
  'getCanonicalActionOutcome',
]);
const REQUIRED_OPERATIONS = OPERATIONS.filter((operation) => operation !== 'initializeCreate');
const RESERVED_PAIR_PROPERTIES = new Set([
  'generation',
  'runtime',
  'coordinator',
  'adapter',
  'eventTarget',
  'presenter',
  'presenterGeneration',
  'statusMount',
  'recoveryMount',
  'presentationConfiguration',
  'form',
  'identityParts',
  'context',
  'targetId',
  'payloadSchemaVersion',
  'runtimeConfiguration',
]);

const isPlainObject = (value) => value !== null
  && typeof value === 'object'
  && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;

const defaultOptionsReader = (key, fallback) => {
  globalThis.Joomla?.loadOptions?.();

  return globalThis.Joomla?.getOptions?.(key, fallback) ?? fallback;
};

const validateRuntimeConfiguration = (configuration, csrf) => {
  if (!isPlainObject(configuration)
    || !isPlainObject(configuration.endpoints)
    || !REQUIRED_OPERATIONS.every(
      (operation) => typeof configuration.endpoints[operation] === 'string'
        && configuration.endpoints[operation].length > 0,
    )
    || (configuration.endpoints.initializeCreate !== undefined
      && (typeof configuration.endpoints.initializeCreate !== 'string'
        || configuration.endpoints.initializeCreate.length === 0))
    || typeof csrf !== 'string'
    || csrf.length === 0) {
    throw new TypeError('The Autosave runtime configuration is invalid.');
  }

  const endpointOperations = configuration.endpoints.initializeCreate
    ? OPERATIONS
    : REQUIRED_OPERATIONS;

  return Object.freeze({
    endpoints: Object.freeze(Object.fromEntries(endpointOperations.map((operation) => [
      operation,
      configuration.endpoints[operation],
    ]))),
    csrf,
  });
};

const sameEndpoints = (first, second) => [...new Set([...Object.keys(first), ...Object.keys(second)])]
  .every((operation) => first[operation] === second[operation]);

const sameIdentityParts = (first, second) => first.length === second.length
  && first.every((part, index) => part === second[index]);

const sameRuntimeResolution = (pair, resolution) => pair
  && resolution
  && pair.form === resolution.form
  && pair.context === resolution.descriptor.context
  && pair.targetId === resolution.descriptor.targetId
  && pair.payloadSchemaVersion === resolution.descriptor.payloadSchemaVersion
  && pair.runtimeConfiguration.csrf === resolution.runtime.csrf
  && sameEndpoints(pair.runtimeConfiguration.endpoints, resolution.runtime.endpoints)
  && sameIdentityParts(pair.identityParts, resolution.identityParts);

const samePresentationConfiguration = (first, second) => first.locale === second.locale
  && first.timeZone === second.timeZone;
const isProvisionalTarget = (targetId) => typeof targetId === 'string'
  && /^p1:[a-f0-9]{64}$/.test(targetId);

const validateIntegrationResolution = (resolution) => {
  if (!isPlainObject(resolution)
    || !isPlainObject(resolution.descriptor)
    || typeof resolution.descriptor.context !== 'string'
    || resolution.descriptor.context.length === 0
    || !(
      (typeof resolution.descriptor.targetId === 'string'
        && resolution.descriptor.targetId.length > 0)
      || (resolution.descriptor.targetId === null && isPlainObject(resolution.createMode))
    )
    || !Number.isInteger(resolution.descriptor.payloadSchemaVersion)
    || resolution.descriptor.payloadSchemaVersion <= 0
    || !resolution.form
    || resolution.form.isConnected === false
    || !Array.isArray(resolution.identityParts)
    || !isPlainObject(resolution.taskPolicy)
    || !isPlainObject(resolution.presentationConfiguration)
    || typeof resolution.presentationConfiguration.locale !== 'string'
    || typeof resolution.presentationConfiguration.timeZone !== 'string'
    || (resolution.pairProperties !== undefined && !isPlainObject(resolution.pairProperties))
    || (resolution.createScope !== undefined
      && !(typeof resolution.createScope === 'string'
        && resolution.createScope.length > 0
        && resolution.createScope.length <= 255
        && !/[\x00-\x1F\x7F]/.test(resolution.createScope)))) {
    throw new TypeError('The Autosave integration resolution is invalid.');
  }

  if (Object.keys(resolution.pairProperties || {}).some(
    (property) => RESERVED_PAIR_PROPERTIES.has(property),
  )) {
    throw new TypeError('The Autosave integration pair properties are invalid.');
  }

  return resolution;
};

/**
 * Resolve one optional generic Autosave presentation root owned by a form.
 *
 * @param {HTMLElement} form
 * @param {string} selector
 * @returns {?HTMLElement}
 */
const resolveAutosaveUiMount = (form, selector) => {
  if (typeof form?.querySelectorAll !== 'function') {
    return null;
  }

  const mounts = form.querySelectorAll(selector);

  if (mounts.length !== 1
    || !mounts[0].isConnected
    || !form.contains(mounts[0])) {
    return null;
  }

  return mounts[0];
};

/**
 * Own one component adapter/runtime/presenter/coordinator pair for a document.
 *
 * Component integrations resolve their explicit form contract and construct
 * their adapter. This controller owns only component-neutral lifecycle work.
 */
export default class AutosaveIntegrationController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    integrationResolver,
    adapterFactory,
    lifecycleSubscriber = null,
    apiClientFactory = (options) => new AutosaveApiClient(options),
    runtimeFactory = (options) => new AutosaveRuntime(options),
    eventTargetFactory = () => new EventTarget(),
    presenterFactory = createAutosavePresenter,
    coordinatorFactory = (options) => new AutosaveCanonicalActionCoordinator(options),
  } = {}) {
    if (!documentSource
      || typeof documentSource.addEventListener !== 'function'
      || typeof documentSource.removeEventListener !== 'function'
      || typeof optionsReader !== 'function'
      || typeof integrationResolver !== 'function'
      || typeof adapterFactory !== 'function'
      || (lifecycleSubscriber !== null && typeof lifecycleSubscriber !== 'function')
      || typeof apiClientFactory !== 'function'
      || typeof runtimeFactory !== 'function'
      || typeof eventTargetFactory !== 'function'
      || typeof presenterFactory !== 'function'
      || typeof coordinatorFactory !== 'function') {
      throw new TypeError('The Autosave integration controller configuration is invalid.');
    }

    this.document = documentSource;
    this.optionsReader = optionsReader;
    this.integrationResolver = integrationResolver;
    this.adapterFactory = adapterFactory;
    this.lifecycleSubscriber = lifecycleSubscriber;
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
    this.handleExternalLifecycle = () => {
      void this.reconcile();
    };
  }

  start() {
    if (this.started || this.destroyed) {
      return this;
    }

    this.started = true;
    this.document.addEventListener('joomla:updated', this.handleUpdated);

    if (this.lifecycleSubscriber) {
      this.unsubscribeLifecycle = this.lifecycleSubscriber(this.handleExternalLifecycle);

      if (typeof this.unsubscribeLifecycle !== 'function') {
        this.document.removeEventListener('joomla:updated', this.handleUpdated);
        this.started = false;
        this.unsubscribeLifecycle = null;
        throw new TypeError('The Autosave integration lifecycle subscription is invalid.');
      }
    }

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

    if (this.activePair
      && isProvisionalTarget(this.activePair.runtime.state?.targetId)
      && this.activePair.runtime.state?.canonicalAction
      && (!resolution || resolution.descriptor.context === this.activePair.context)) {
      // A DPU replacement may publish the canonical target before the Ajax
      // completion event. Keep the provisional coordinator alive until its
      // durable operation outcome has been reconciled.
      this.activePair.deferredTargetChange = true;

      return true;
    }

    const generation = this.invalidatePairs();

    if (!resolution) {
      return false;
    }

    let adapter;

    try {
      adapter = this.adapterFactory(resolution);
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
        throw new TypeError('The Autosave integration event target is invalid.');
      }

      runtime = this.runtimeFactory({
        apiClient,
        adapter,
        context: resolution.descriptor.context,
        targetId: resolution.descriptor.targetId,
        schemaVersion: resolution.descriptor.payloadSchemaVersion,
        eventTarget,
        createMode: resolution.createMode || null,
        createScope: resolution.createScope || null,
      });
      coordinator = this.coordinatorFactory({
        form: resolution.form,
        runtime,
        taskPolicy: resolution.taskPolicy,
        allowDetachedDuringCanonical: resolution.descriptor.targetId === null
          || resolution.descriptor.targetId.startsWith('p1:'),
      });

      if (!coordinator
        || typeof coordinator.start !== 'function'
        || typeof coordinator.destroy !== 'function') {
        throw new TypeError('The Autosave canonical action coordinator is invalid.');
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
      identityParts: [...resolution.identityParts],
      context: resolution.descriptor.context,
      targetId: resolution.descriptor.targetId,
      payloadSchemaVersion: resolution.descriptor.payloadSchemaVersion,
      runtimeConfiguration: resolution.runtime,
      ...(resolution.pairProperties || {}),
    };
    pair.handleRuntimeState = () => {
      if (pair.targetId === null && isProvisionalTarget(pair.runtime.state?.targetId)) {
        pair.targetId = pair.runtime.state.targetId;
      }

      if (pair.deferredTargetChange
        && pair.runtime.state?.canonicalAction === null
        && pair.runtime.state?.status === 'clean') {
        void this.reconcile();
      }
    };
    eventTarget.addEventListener('joomla:autosave-statechange', pair.handleRuntimeState);
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

  resolve() {
    const integration = this.integrationResolver();

    if (!integration) {
      return null;
    }

    validateIntegrationResolution(integration);

    const runtime = validateRuntimeConfiguration(
      this.optionsReader(RUNTIME_OPTIONS_KEY, null),
      this.optionsReader('csrf.token', ''),
    );

    if (integration.createMode && !runtime.endpoints.initializeCreate) {
      throw new TypeError('The Autosave create endpoint is unavailable.');
    }

    return {
      ...integration,
      runtime,
    };
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
    this.integrationResolver = null;
    this.adapterFactory = null;
    this.lifecycleSubscriber = null;
  }

  destroyPair(pair) {
    pair.presenterGeneration += 1;
    pair.presenter?.destroy();
    pair.presenter = null;
    pair.eventTarget.removeEventListener('joomla:autosave-statechange', pair.handleRuntimeState);
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
  OPERATIONS,
  RUNTIME_OPTIONS_KEY,
  defaultOptionsReader,
  isPlainObject,
  resolveAutosaveUiMount,
  sameRuntimeResolution,
  validateIntegrationResolution,
  validateRuntimeConfiguration,
};
