/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import ModuleAutosaveController, {
  OPTIONS_KEY,
  TASK_POLICY,
  validateConfiguration,
} from '../../../../media_source/com_modules/src/module-autosave-controller.es6.js';

const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_IDS = Object.freeze({
  title: 'jform_title',
  note: 'jform_note',
  version_note: 'jform_version_note',
  showtitle: 'jform_showtitle',
  position: 'jform_position',
  content: 'jform_content',
});
const DYNAMIC_SCHEMA = Object.freeze({
  fingerprint: 'a'.repeat(64),
  fields: Object.freeze([Object.freeze({ path: Object.freeze(['params', 'prepare_content']), id: 'jform_params_prepare_content', kind: 'enum', values: Object.freeze(['0', '1']) })]),
});
const CREATE_SCOPE = `md1:0:mod_custom:${'a'.repeat(64)}`;

const createBindingDescriptor = Object.freeze({
  initializationKey: 'module-create-form',
  targetId: null,
  formInstanceId: 'module-create-form',
  onInitialized: () => {},
  acquire: async () => true,
  release: () => {},
  onCanonicalSuccess: () => {},
});

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

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.checked = false;
    this.isConnected = true;
    this.options = [];
  }
}

class FakeDocument extends EventTarget {
  constructor() {
    super();
    this.elements = new Map();
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
    this.state = {
      targetId: options.targetId,
      canonicalAction: null,
      status: 'clean',
    };
  }

  start() {
    this.startCalls += 1;
    this.unsubscribe = this.options.adapter.subscribe(() => {});

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
  }

  destroy() {}
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

const createFixture = ({
  mounted = true,
  mode = 'existing',
  createScope,
  boundTarget = null,
} = {}) => {
  const documentSource = new FakeDocument();
  const all = [];
  const form = {
    isConnected: true,
    id: 'module-form',
    contains: (node) => all.includes(node),
    querySelectorAll: (selector) => {
      const name = selector.match(/name="([^"]+)/)?.[1];
      return all.filter((node) => node.name !== undefined && (selector.includes('^=') ? node.name.startsWith(name) : node.name === name));
    },
  };
  const staticFields = Object.fromEntries(['title', 'note', 'version_note', 'position', 'content'].map((key) => [key, new FakeElement(FIELD_IDS[key], key === 'position' ? 'position-7' : '')]));
  const showtitle = ['0', '1'].map((value, index) => {
    const element = new FakeElement(`${FIELD_IDS.showtitle}${index}`, value);
    element.name = 'jform[showtitle]';

    return element;
  });
  const dynamic = new FakeElement('jform_params_prepare_content', '0');
  dynamic.name = 'jform[params][prepare_content]';
  Object.values(staticFields).forEach((element) => all.push(element));
  showtitle.forEach((element) => all.push(element));
  all.push(dynamic);
  documentSource.elements.set(form.id, form);
  Object.values(staticFields).forEach((element) => documentSource.elements.set(element.id, element));
  documentSource.elements.set(dynamic.id, dynamic);
  if (!mounted) {
    form.isConnected = false;
  }
  const options = {
    [OPTIONS_KEY]: {
      enabled: true,
      context: 'com_modules.module',
      mode,
      targetId: mode === 'create' ? null : '7',
      payloadSchemaVersion: 1,
      formId: 'module-form',
      fieldIds: { ...FIELD_IDS },
      dynamicSchema: DYNAMIC_SCHEMA,
      ...(createScope !== undefined ? { createScope } : {}),
    },
    [RUNTIME_OPTIONS_KEY]: { endpoints: { ...(mode === 'create' ? endpoints : existingEndpoints) } },
    'csrf.token': 'csrf-token',
  };
  let bindingCalls = 0;
  const runtimes = [];
  const clients = [];
  const coordinators = [];
  const controller = new ModuleAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    createBindingFactory: () => {
      bindingCalls += 1;

      return {
        descriptor: () => Object.freeze({ ...createBindingDescriptor, targetId: boundTarget }),
        release: createBindingDescriptor.release,
        context: 'com_modules.module',
      };
    },
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
    eventTargetFactory: () => new EventTarget(),
    presenterFactory: (configuration) => new FakePresenter(configuration),
    coordinatorFactory: (configuration) => {
      const coordinator = new FakeCoordinator(configuration);
      coordinators.push(coordinator);

      return coordinator;
    },
  });

  return {
    bindingCalls: () => bindingCalls,
    clients,
    controller,
    coordinators,
    document: documentSource,
    options,
    runtimes,
  };
};

const settle = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

test('create-mode configuration accepts a null target and a bounded descriptor candidate', () => {
  const base = {
    enabled: true,
    context: 'com_modules.module',
    payloadSchemaVersion: 1,
    formId: 'module-form',
    fieldIds: { ...FIELD_IDS },
    dynamicSchema: DYNAMIC_SCHEMA,
  };
  const create = validateConfiguration({ ...base, mode: 'create', targetId: null, createScope: CREATE_SCOPE });
  assert.equal(create.mode, 'create');
  assert.equal(create.targetId, null);
  assert.equal(create.createScope, CREATE_SCOPE);

  // A canonical target is never valid in create mode and create data is never
  // accepted for an existing record.
  assert.throws(() => validateConfiguration({ ...base, mode: 'create', targetId: '7' }), TypeError);
  assert.throws(() => validateConfiguration({ ...base, mode: 'existing', targetId: null }), TypeError);
  assert.throws(() => validateConfiguration({ ...base, mode: 'create', targetId: null, createScope: 'bad scope\n' }), TypeError);
  assert.throws(() => validateConfiguration({ ...base, mode: 'create', targetId: null, createScope: '' }), TypeError);

  // The scope-free existing contract is unchanged.
  const existing = validateConfiguration({ ...base, targetId: 7 });
  assert.equal(existing.targetId, '7');
});

test('a new Module form boots one runtime with its descriptor candidate', async () => {
  const fixture = createFixture({ mode: 'create', createScope: CREATE_SCOPE });
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.bindingCalls(), 1);
  assert.equal(fixture.runtimes[0].options.context, 'com_modules.module');
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.equal(fixture.runtimes[0].options.schemaVersion, 1);
  assert.deepEqual(fixture.runtimes[0].options.createMode, createBindingDescriptor);
  assert.equal(fixture.runtimes[0].options.createScope, CREATE_SCOPE);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, 'index.php?task=autosave.initializeCreate');
  assert.equal(fixture.coordinators[0].startCalls, 1);
});

test('repeated navigation events never duplicate the new Module runtime', async () => {
  const fixture = createFixture({ mode: 'create', createScope: CREATE_SCOPE });
  fixture.controller.start();
  await fixture.controller.reconcile();
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.bindingCalls(), 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.runtimes[0].destroyCalls, 0);
});

test('a restored P1 binding routes the runtime target through the create binding', async () => {
  const bound = `p1:${'c'.repeat(64)}`;
  const fixture = createFixture({ mode: 'create', createScope: CREATE_SCOPE, boundTarget: bound });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, bound);
  assert.deepEqual(fixture.runtimes[0].options.createMode, { ...createBindingDescriptor, targetId: bound });
});

test('an existing Module record still boots without create state', async () => {
  const fixture = createFixture({ mode: 'existing' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.bindingCalls(), 0);
  assert.equal(fixture.runtimes[0].options.targetId, '7');
  assert.equal(fixture.runtimes[0].options.createMode, null);
  assert.equal(fixture.runtimes[0].options.createScope, null);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, undefined);
  assert.equal(fixture.coordinators[0].options.taskPolicy, TASK_POLICY);
});

test('create mode refuses to activate when the create endpoint is unavailable', async () => {
  const fixture = createFixture({ mode: 'create', createScope: CREATE_SCOPE });
  fixture.options[RUNTIME_OPTIONS_KEY].endpoints = { ...existingEndpoints };
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 0);
});
