/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import NoteAutosaveController, {
  OPTIONS_KEY,
  TASK_POLICY,
} from '../../../../media_source/com_users/src/note-autosave-controller.es6.js';

const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
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
  constructor(value = '<p>Note</p>', supported = true) {
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
  constructor(options) {
    this.options = options;
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

    return this;
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

const fieldIds = Object.freeze({
  subject: 'jform_subject',
  body: 'jform_body',
});

const createBindingDescriptor = Object.freeze({
  initializationKey: 'note-create-form',
  targetId: null,
  formInstanceId: 'note-create-form',
  onInitialized: () => {},
  acquire: async () => true,
  release: () => {},
  onCanonicalSuccess: () => {},
});

const createFixture = ({
  mounted = true,
  mode = 'existing',
  createBindingFactory,
} = {}) => {
  const documentSource = new FakeDocument();
  const registry = new FakeEditorRegistry();
  const options = {
    [OPTIONS_KEY]: {
      enabled: true,
      context: 'com_users.note',
      mode,
      targetId: mode === 'create' ? null : '7',
      payloadSchemaVersion: 1,
      formId: 'note-form',
      fieldIds: { ...fieldIds },
    },
    [RUNTIME_OPTIONS_KEY]: { endpoints: { ...endpoints } },
    'csrf.token': 'csrf-token',
  };
  const form = new FakeForm('note-form');
  const fields = {
    subject: new FakeElement(fieldIds.subject, ''),
    body: new FakeElement(fieldIds.body, ''),
  };
  Object.values(fields).forEach((field) => form.elements.add(field));
  const statusMount = new FakeElement('note-form-autosave-status');
  const recoveryMount = new FakeElement('note-form-autosave-recovery');
  form.mounts.set('[data-joomla-autosave-status-ui]', statusMount);
  form.mounts.set('[data-joomla-autosave-recovery-ui]', recoveryMount);
  form.elements.add(statusMount);
  form.elements.add(recoveryMount);
  const editor = new FakeEditor();

  const mount = () => {
    documentSource.elements.set(form.id, form);
    Object.values(fields).forEach((field) => documentSource.elements.set(field.id, field));
    registry.instances.set(fields.body.id, editor);
  };

  if (mounted) {
    mount();
  }

  const runtimes = [];
  const clients = [];
  const eventTargets = [];
  const presenters = [];
  const coordinators = [];
  const controller = new NoteAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    editorRegistry: registry,
    createBindingFactory: createBindingFactory || (() => ({
      descriptor: () => createBindingDescriptor,
      release: createBindingDescriptor.release,
      context: 'com_users.note',
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
  };
};

const settle = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

test('an existing User Note boots exactly once with its canonical target', async () => {
  const fixture = createFixture({ mode: 'existing' });
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 1);
  assert.equal(fixture.registry.subscriptionCount, 1);
  assert.equal(fixture.runtimes[0].options.context, 'com_users.note');
  assert.equal(fixture.runtimes[0].options.targetId, '7');
  assert.equal(fixture.runtimes[0].options.createMode, null);
  assert.equal(fixture.runtimes[0].options.schemaVersion, 1);
  assert.strictEqual(fixture.presenters[0].options.statusMount, fixture.statusMount);
  assert.strictEqual(fixture.presenters[0].options.recoveryMount, fixture.recoveryMount);
  assert.strictEqual(fixture.coordinators[0].options.taskPolicy, TASK_POLICY);
  assert.equal(fixture.coordinators[0].startCalls, 1);
});

test('a new User Note create mode boots one unresolved runtime with the shared create binding', async () => {
  const fixture = createFixture({ mode: 'create' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.strictEqual(fixture.runtimes[0].options.createMode, createBindingDescriptor);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, 'index.php?task=autosave.initializeCreate');
  assert.equal(fixture.runtimes[0].changeCalls, 0);
  assert.equal(fixture.presenters.length, 1);
});

test('a User Note form mounted after navigation is picked up without a full reload', async () => {
  const fixture = createFixture({ mounted: false, mode: 'create' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 0);

  fixture.mount();
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.strictEqual(fixture.runtimes[0].options.createMode, createBindingDescriptor);
  assert.equal(fixture.runtimes[0].changeCalls, 0);
});

test('repeated navigation and editor lifecycle events never duplicate the Note runtime', async () => {
  const fixture = createFixture({ mode: 'create' });
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
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 1);
});

test('refreshed script options replace an active create pair with the existing target', async () => {
  const fixture = createFixture({ mode: 'create' });
  fixture.controller.start();
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);

  fixture.options[OPTIONS_KEY].mode = 'existing';
  fixture.options[OPTIONS_KEY].targetId = '7';
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.runtimes[1].options.targetId, '7');
  assert.equal(fixture.runtimes[1].options.createMode, null);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[1].startCalls, 1);
});

test('missing, disconnected or editor-less forms stay inactive', async (t) => {
  const cases = [
    ['missing form', (fixture) => fixture.document.elements.delete('note-form')],
    ['disconnected form', (fixture) => { fixture.form.isConnected = false; }],
    ['missing subject', (fixture) => fixture.document.elements.delete('jform_subject')],
    ['missing body field', (fixture) => fixture.document.elements.delete('jform_body')],
    ['missing editor', (fixture) => fixture.registry.instances.clear()],
  ];

  for (const [name, mutate] of cases) {
    await t.test(name, async () => {
      const fixture = createFixture({ mode: 'create' });
      mutate(fixture);
      fixture.controller.start();
      await fixture.controller.reconcile();
      assert.equal(fixture.runtimes.length, 0);
    });
  }
});
