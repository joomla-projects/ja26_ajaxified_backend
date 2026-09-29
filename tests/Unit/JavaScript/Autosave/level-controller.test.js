/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import LevelAutosaveAdapter, { validatePayload } from '../../../../media_source/com_users/src/level-autosave-adapter.es6.js';
import LevelAutosaveController, {
  FIELD_KEYS,
  OPTIONS_KEY,
  TASK_POLICY,
  validateConfiguration,
} from '../../../../media_source/com_users/src/level-autosave-controller.es6.js';

const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const RULES_SELECTOR = 'input[name="jform[rules][]"]';

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
  }
}

class FakeCheckbox extends FakeElement {
  constructor(id, value, checked = false) {
    super(id, value);
    this.checked = checked;
    this.name = 'jform[rules][]';
  }
}

class FakeForm extends FakeElement {
  constructor(id) {
    super(id);
    this.children = new Set();
    this.mounts = new Map();
    this.rules = [];
  }

  contains(element) {
    return this.children.has(element) && element.isConnected;
  }

  querySelectorAll(selector) {
    if (selector === RULES_SELECTOR) {
      return this.rules;
    }

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

const createBindingDescriptor = Object.freeze({
  initializationKey: 'level-create-form',
  targetId: null,
  formInstanceId: 'level-create-form',
  onInitialized: () => {},
  acquire: async () => true,
  release: () => {},
  onCanonicalSuccess: () => {},
});

const groupValues = ['1', '2', '8', '9'];

const createFixture = ({
  mounted = true,
  mode = 'existing',
  checked = [],
  createBindingFactory,
} = {}) => {
  const documentSource = new FakeDocument();
  const options = {
    [OPTIONS_KEY]: {
      enabled: true,
      context: 'com_users.level',
      mode,
      targetId: mode === 'create' ? null : '7',
      payloadSchemaVersion: 2,
      formId: 'level-form',
      fieldIds: { title: 'jform_title', rules: 'jform_rules' },
      locale: 'en-GB',
      timeZone: 'UTC',
    },
    [RUNTIME_OPTIONS_KEY]: { endpoints: { ...endpoints } },
    'csrf.token': 'csrf-token',
  };
  const form = new FakeForm('level-form');
  const title = new FakeElement('jform_title', '');
  const rules = groupValues.map((value, index) => new FakeCheckbox(`group_${value}_${index}`, value, checked.includes(Number(value))));

  const mount = () => {
    documentSource.elements.set(form.id, form);
    documentSource.elements.set(title.id, title);
    form.children.add(title);
    rules.forEach((control) => form.children.add(control));
    form.rules = rules;
  };

  if (mounted) {
    mount();
  }

  const statusMount = new FakeElement('level-form-autosave-status');
  const recoveryMount = new FakeElement('level-form-autosave-recovery');
  form.mounts.set('[data-joomla-autosave-status-ui]', statusMount);
  form.mounts.set('[data-joomla-autosave-recovery-ui]', recoveryMount);
  form.children.add(statusMount);
  form.children.add(recoveryMount);

  const runtimes = [];
  const clients = [];
  const eventTargets = [];
  const presenters = [];
  const coordinators = [];
  const controller = new LevelAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    createBindingFactory: createBindingFactory || (() => ({
      descriptor: () => createBindingDescriptor,
      release: createBindingDescriptor.release,
      context: 'com_users.level',
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
    eventTargets,
    form,
    mount,
    options,
    presenters,
    recoveryMount,
    rules,
    runtimes,
    statusMount,
    title,
  };
};

const settle = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

test('the Access Level payload allowlist carries ordered integer groups only', () => {
  assert.deepEqual(validatePayload({ title: 'Special', rules: [1, 8, 2] }), { title: 'Special', rules: [1, 8, 2] });
  assert.deepEqual(validatePayload({ title: '', rules: [] }), { title: '', rules: [] });
  assert.deepEqual(FIELD_KEYS, ['title', 'rules']);
  assert.equal(Object.keys(TASK_POLICY).length, 5);

  for (const invalid of [
    { title: 'Special' },
    { title: 'Special', rules: '1' },
    { title: 'Special', rules: ['1'] },
    { title: 'Special', rules: [0] },
    { title: 'Special', rules: [-1] },
    { title: 'Special', rules: [2147483648] },
    { title: 'x'.repeat(101), rules: [] },
    { title: 'Special', rules: Array.from({ length: 101 }, (_, index) => index + 1) },
    { title: 'Special', rules: [1], extra: 2 },
  ]) {
    assert.throws(() => validatePayload(invalid), TypeError);
  }
});

test('structured group selection roundtrips in checkbox DOM order', () => {
  const form = new FakeForm('level-form');
  const title = new FakeElement('jform_title', 'Draft');
  const rules = groupValues.map((value, index) => new FakeCheckbox(`group_${value}_${index}`, value, index % 2 === 0));
  form.children.add(title);
  rules.forEach((control) => form.children.add(control));
  form.rules = rules;
  const adapter = new LevelAutosaveAdapter({
    descriptor: { context: 'com_users.level', targetId: '7', payloadSchemaVersion: 2 },
    form,
    fields: { title, rules },
  });

  assert.deepEqual(adapter.initializeBaseline().capture(), { title: 'Draft', rules: [1, 8] });

  adapter.apply({ title: 'Restored', rules: [2, 8, 9] });
  assert.deepEqual(adapter.capture(), { title: 'Restored', rules: [2, 8, 9] });
  assert.deepEqual(rules.map((control) => [Number(control.value), control.checked]), [[1, false], [2, true], [8, true], [9, true]]);

  adapter.apply({ title: 'Cleared', rules: [] });
  assert.deepEqual(adapter.capture(), { title: 'Cleared', rules: [] });
});

test('a new Access Level form bootstraps an unresolved runtime with its browser lineage', async () => {
  const fixture = createFixture({ mode: 'create' });
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 1);
  assert.equal(fixture.runtimes[0].options.context, 'com_users.level');
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.strictEqual(fixture.runtimes[0].options.createMode, createBindingDescriptor);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, 'index.php?task=autosave.initializeCreate');
  assert.strictEqual(fixture.presenters[0].options.runtime, fixture.runtimes[0]);
  assert.strictEqual(fixture.coordinators[0].options.taskPolicy, TASK_POLICY);
  assert.equal(fixture.coordinators[0].startCalls, 1);
});

test('an existing Access Level record still boots with its canonical target', async () => {
  const fixture = createFixture({ mode: 'existing' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, '7');
  assert.equal(fixture.runtimes[0].options.createMode, null);
  assert.equal(fixture.presenters.length, 1);
});

test('repeated navigation events never duplicate the Level runtime', async () => {
  const fixture = createFixture({ mode: 'create' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.runtimes[0].destroyCalls, 0);
  assert.equal(fixture.presenters.length, 1);
  assert.equal(fixture.coordinators.length, 1);
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
});

test('missing title or a missing group grid keep the Level form inactive', async (t) => {
  for (const [name, mutate] of [
    ['missing form', (fixture) => { fixture.form.isConnected = false; }],
    ['missing title', (fixture) => { fixture.title.isConnected = false; }],
    ['missing group grid', (fixture) => { fixture.rules.forEach((control) => { control.isConnected = false; }); }],
  ]) {
    await t.test(name, async () => {
      const fixture = createFixture({ mode: 'create' });
      mutate(fixture);
      fixture.controller.start();
      await fixture.controller.reconcile();
      assert.equal(fixture.runtimes.length, 0);
      assert.equal(fixture.presenters.length, 0);
    });
  }
});

test('configuration validation enforces mode and field shape', () => {
  const configuration = (mode, targetId, fields) => ({
    enabled: true, context: 'com_users.level', mode, targetId, payloadSchemaVersion: 2,
    formId: 'level-form', fieldIds: Object.fromEntries(fields.map((field) => [field, `jform_${field}`])),
  });

  assert.equal(validateConfiguration(configuration('existing', '7', ['title', 'rules'])).targetId, '7');
  assert.equal(validateConfiguration(configuration('create', null, ['title', 'rules'])).targetId, null);
  assert.equal(validateConfiguration({ enabled: false }), null);
  assert.throws(() => validateConfiguration(configuration('create', '7', ['title', 'rules'])), TypeError);
  assert.throws(() => validateConfiguration(configuration('existing', null, ['title', 'rules'])), TypeError);
  assert.throws(() => validateConfiguration(configuration('existing', '7', ['title'])), TypeError);
  assert.throws(() => validateConfiguration(configuration('existing', '0', ['title', 'rules'])), TypeError);
});
