/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import CategoryAutosaveController, {
  OPTIONS_KEY,
  TASK_POLICY,
} from '../../../../media_source/com_categories/src/category-autosave-controller.es6.js';

const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
    this.options = [];
  }
}

class FakeForm extends FakeElement {
  constructor(id) {
    super(id);
    this.elements = new Set();
    this.mounts = new Map();
  }

  contains(element) {
    return this.elements.has(element) && element.isConnected;
  }

  querySelectorAll(selector) {
    const mount = this.mounts.get(selector);

    if (!mount?.isConnected) {
      return [];
    }

    return [mount];
  }
}

class FakeDocument extends EventTarget {
  constructor() {
    super();
    this.elements = new Map();
    this.listenerCounts = new Map();
  }

  addEventListener(type, callback, options) {
    super.addEventListener(type, callback, options);
    this.listenerCounts.set(type, (this.listenerCounts.get(type) || 0) + 1);
  }

  removeEventListener(type, callback, options) {
    super.removeEventListener(type, callback, options);
    this.listenerCounts.set(type, Math.max(0, (this.listenerCounts.get(type) || 0) - 1));
  }

  getElementById(id) {
    return this.elements.get(id) || null;
  }
}

class FakeEditor {
  constructor(value = '<p>Category</p>', supported = true) {
    this.value = value;
    this.supported = supported;
    this.callbacks = new Set();
    this.unsubscribeCalls = 0;
  }

  supportsChangeObservation() {
    return this.supported;
  }

  subscribeChange(callback) {
    this.callbacks.add(callback);
    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.unsubscribeCalls += 1;
      this.callbacks.delete(callback);
    };
  }

  getValue() {
    return this.value;
  }

  setValue(value) {
    this.value = value;
    [...this.callbacks].forEach((callback) => callback());

    return this;
  }
}

class FakeEditorRegistry {
  constructor() {
    this.instances = new Map();
    this.lifecycleCallbacks = new Set();
    this.subscriptionCount = 0;
    this.unsubscribeCount = 0;
  }

  get(id) {
    return this.instances.get(id) || false;
  }

  subscribeLifecycle(callback) {
    this.lifecycleCallbacks.add(callback);
    this.subscriptionCount += 1;
    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.unsubscribeCount += 1;
      this.lifecycleCallbacks.delete(callback);
    };
  }

  emit(detail) {
    [...this.lifecycleCallbacks].forEach((callback) => callback(detail));
  }
}

class FakeRuntime {
  constructor(options, startResult = null) {
    this.options = options;
    this.startResult = startResult;
    this.startCalls = 0;
    this.destroyCalls = 0;
    this.changeCalls = 0;
    this.state = {
      targetId: options.targetId,
      canonicalAction: null,
      status: 'clean',
    };
  }

  start() {
    this.startCalls += 1;
    this.unsubscribe = this.options.adapter.subscribe(() => {
      this.changeCalls += 1;
    });

    return this.startResult || this;
  }

  destroy() {
    if (this.destroyCalls > 0) {
      return;
    }

    this.destroyCalls += 1;
    this.unsubscribe?.();
    this.options.adapter.destroy();
  }
}

class FakePresenter {
  constructor(options) {
    this.options = options;
    this.destroyCalls = 0;
  }

  destroy() {
    this.destroyCalls += 1;
  }
}

class FakeCoordinator {
  constructor(options) {
    this.options = options;
    this.startCalls = 0;
    this.destroyCalls = 0;
  }

  start() {
    this.startCalls += 1;

    return this;
  }

  destroy() {
    this.destroyCalls += 1;
  }
}

const endpoints = Object.freeze({
  initialize: 'index.php?task=autosave.initialize',
  initializeCreate: 'index.php?task=autosave.initializeCreate',
  preserve: 'index.php?task=autosave.preserve',
  detect: 'index.php?task=autosave.detect',
  read: 'index.php?task=autosave.read',
  discard: 'index.php?task=autosave.discard',
  prepareCanonicalAction: 'index.php?task=autosave.prepareCanonicalAction',
  getCanonicalActionOutcome: 'index.php?task=autosave.getCanonicalActionOutcome',
});

const existingEndpoints = Object.freeze(Object.fromEntries(
  Object.entries(endpoints).filter(([operation]) => operation !== 'initializeCreate'),
));

const fieldIds = Object.freeze({
  title: 'jform_title',
  note: 'jform_note',
  description: 'jform_description',
  version_note: 'jform_version_note',
  metadesc: 'jform_metadesc',
  metakey: 'jform_metakey',
  parent_id: 'jform_parent_id',
});

const createBindingDescriptor = Object.freeze({
  initializationKey: 'category-create-form',
  targetId: null,
  formInstanceId: 'category-create-form',
  onInitialized: () => {},
  acquire: async () => true,
  release: () => {},
  onCanonicalSuccess: () => {},
});

const createFixture = ({
  mounted = true,
  mode = 'existing',
  createScope,
  createBindingFactory,
} = {}) => {
  const documentSource = new FakeDocument();
  const registry = new FakeEditorRegistry();
  const options = {
    [OPTIONS_KEY]: {
      enabled: true,
      context: 'com_categories.category',
      mode,
      targetId: mode === 'create' ? null : '7',
      payloadSchemaVersion: 2,
      formId: 'item-form',
      fieldIds: { ...fieldIds },
      locale: 'en-GB',
      timeZone: 'UTC',
      ...(createScope !== undefined ? { createScope } : {}),
    },
    [RUNTIME_OPTIONS_KEY]: {
      endpoints: { ...(mode === 'create' ? endpoints : existingEndpoints) },
    },
    'csrf.token': 'csrf-token',
  };
  const form = new FakeForm('item-form');
  const fields = {
    title: new FakeElement(fieldIds.title, 'Category title'),
    note: new FakeElement(fieldIds.note, ''),
    description: new FakeElement(fieldIds.description, ''),
    version_note: new FakeElement(fieldIds.version_note, ''),
    metadesc: new FakeElement(fieldIds.metadesc, ''),
    metakey: new FakeElement(fieldIds.metakey, ''),
    parent_id: new FakeElement(fieldIds.parent_id, '1'),
  };
  Object.values(fields).forEach((field) => form.elements.add(field));
  const statusMount = new FakeElement('item-form-autosave-status');
  const recoveryMount = new FakeElement('item-form-autosave-recovery');
  form.mounts.set('[data-joomla-autosave-status-ui]', statusMount);
  form.mounts.set('[data-joomla-autosave-recovery-ui]', recoveryMount);
  form.elements.add(statusMount);
  form.elements.add(recoveryMount);
  const editor = new FakeEditor();

  const mount = () => {
    documentSource.elements.set(form.id, form);
    Object.values(fields).forEach((field) => documentSource.elements.set(field.id, field));
    registry.instances.set(fields.description.id, editor);
  };

  if (mounted) {
    mount();
  }

  const runtimes = [];
  const clients = [];
  const eventTargets = [];
  const presenters = [];
  const coordinators = [];
  const controller = new CategoryAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    editorRegistry: registry,
    createBindingFactory: createBindingFactory || (() => ({
      descriptor: () => createBindingDescriptor,
      release: createBindingDescriptor.release,
      context: 'com_categories.category',
    })),
    apiClientFactory: (configuration) => {
      const client = { configuration };
      clients.push(client);

      return client;
    },
    runtimeFactory: (configuration) => {
      const runtime = new FakeRuntime(configuration);
      runtimes.push(runtime);

      return runtime;
    },
    eventTargetFactory: () => {
      const eventTarget = new EventTarget();
      eventTargets.push(eventTarget);

      return eventTarget;
    },
    presenterFactory: (configuration) => {
      const presenter = new FakePresenter(configuration);
      presenters.push(presenter);

      return presenter;
    },
    coordinatorFactory: (configuration) => {
      const coordinator = new FakeCoordinator(configuration);
      coordinators.push(coordinator);

      return coordinator;
    },
  });

  return {
    clients,
    controller,
    coordinators,
    document: documentSource,
    editor,
    eventTargets,
    fields,
    form,
    mount,
    options,
    presenters,
    recoveryMount,
    registry,
    runtimes,
    statusMount,
    replaceForm() {
      const replacement = new FakeForm('item-form');
      const replacementFields = {
        title: new FakeElement(fieldIds.title, 'Replacement'),
        note: new FakeElement(fieldIds.note, ''),
        description: new FakeElement(fieldIds.description, ''),
        version_note: new FakeElement(fieldIds.version_note, ''),
        metadesc: new FakeElement(fieldIds.metadesc, ''),
        metakey: new FakeElement(fieldIds.metakey, ''),
        parent_id: new FakeElement(fieldIds.parent_id, '1'),
      };
      Object.values(replacementFields).forEach((field) => replacement.elements.add(field));
      const replacementStatusMount = new FakeElement('item-form-autosave-status-replacement');
      const replacementRecoveryMount = new FakeElement('item-form-autosave-recovery-replacement');
      replacement.mounts.set('[data-joomla-autosave-status-ui]', replacementStatusMount);
      replacement.mounts.set('[data-joomla-autosave-recovery-ui]', replacementRecoveryMount);
      replacement.elements.add(replacementStatusMount);
      replacement.elements.add(replacementRecoveryMount);
      form.isConnected = false;
      statusMount.isConnected = false;
      recoveryMount.isConnected = false;
      Object.values(fields).forEach((field) => {
        field.isConnected = false;
      });
      const replacementEditor = new FakeEditor();
      documentSource.elements.set(replacement.id, replacement);
      Object.values(replacementFields).forEach((field) => documentSource.elements.set(field.id, field));
      registry.instances.set(fieldIds.description, replacementEditor);

      return {
        form: replacement,
        fields: replacementFields,
        editor: replacementEditor,
        recoveryMount: replacementRecoveryMount,
        statusMount: replacementStatusMount,
      };
    },
  };
};

const settle = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

test('a new Category form bootstraps immediately with its immutable create scope', async () => {
  const fixture = createFixture({ mode: 'create', createScope: 'com_content' });
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 1);
  assert.equal(fixture.registry.subscriptionCount, 1);
  assert.equal(fixture.runtimes[0].options.context, 'com_categories.category');
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.strictEqual(fixture.runtimes[0].options.createMode, createBindingDescriptor);
  assert.equal(fixture.runtimes[0].options.createScope, 'com_content');
  assert.equal(fixture.runtimes[0].options.schemaVersion, 2);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, 'index.php?task=autosave.initializeCreate');
  assert.strictEqual(fixture.presenters[0].options.runtime, fixture.runtimes[0]);
  assert.strictEqual(fixture.presenters[0].options.statusMount, fixture.statusMount);
  assert.strictEqual(fixture.presenters[0].options.recoveryMount, fixture.recoveryMount);
  assert.strictEqual(fixture.coordinators[0].options.taskPolicy, TASK_POLICY);
  assert.equal(fixture.coordinators[0].startCalls, 1);
});

test('a fresh new Category form stays unresolved without detect until the form appears', async () => {
  const fixture = createFixture({ mounted: false, mode: 'create', createScope: 'com_content' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 0);
  assert.equal(fixture.presenters.length, 0);

  fixture.mount();
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.equal(fixture.runtimes[0].options.createScope, 'com_content');
  assert.equal(fixture.runtimes[0].changeCalls, 0);
});

test('refreshed script options replace an active create pair with the existing target', async () => {
  const fixture = createFixture({ mode: 'create', createScope: 'com_content' });
  fixture.controller.start();
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);

  fixture.options[OPTIONS_KEY].mode = 'existing';
  fixture.options[OPTIONS_KEY].targetId = '7';
  delete fixture.options[OPTIONS_KEY].createScope;
  fixture.options[RUNTIME_OPTIONS_KEY].endpoints = { ...existingEndpoints };
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.runtimes[1].options.targetId, '7');
  assert.equal(fixture.runtimes[1].options.createMode, null);
  assert.equal(fixture.runtimes[1].options.createScope, null);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[1].startCalls, 1);
});

test('repeated navigation events never duplicate the Category runtime', async () => {
  const fixture = createFixture({ mode: 'create', createScope: 'com_content' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  fixture.registry.emit({ type: 'registered', id: 'another-editor', editorType: 'none' });
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.runtimes[0].destroyCalls, 0);
  assert.equal(fixture.presenters.length, 1);
  assert.equal(fixture.presenters[0].destroyCalls, 0);
  assert.equal(fixture.coordinators.length, 1);
  assert.equal(fixture.coordinators[0].startCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 0);
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 1);
});

test('a replaced new Category form destroys the old pair and boots the replacement once', async () => {
  const fixture = createFixture({ mode: 'create', createScope: 'com_content' });
  fixture.controller.start();
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);

  const replacement = fixture.replaceForm();
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.runtimes[1].startCalls, 1);
  assert.equal(fixture.runtimes[1].options.createScope, 'com_content');
  assert.equal(fixture.coordinators.length, 2);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.strictEqual(fixture.controller.activePair.form, replacement.form);
  assert.notStrictEqual(fixture.eventTargets[0], fixture.eventTargets[1]);
  assert.strictEqual(fixture.editor.unsubscribeCalls, 1);
});

test('an existing Category record still boots with its canonical target', async () => {
  const fixture = createFixture({ mode: 'existing' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, '7');
  assert.equal(fixture.runtimes[0].options.createMode, null);
  assert.equal(fixture.runtimes[0].options.createScope, null);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, undefined);
  assert.equal(fixture.presenters.length, 1);
});
