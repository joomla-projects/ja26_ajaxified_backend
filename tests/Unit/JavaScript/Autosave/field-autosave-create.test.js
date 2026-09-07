/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import FieldAutosaveController, {
  OPTIONS_KEY,
  TASK_POLICY,
  validateConfiguration,
} from '../../../../media_source/com_fields/src/field-autosave-controller.es6.js';

const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_IDS = Object.freeze(Object.fromEntries(
  ['title', 'name', 'label', 'description', 'default_value', 'note', 'required', 'only_use_in_subform']
    .map((key) => [key, `jform_${key}`]),
));
const DYNAMIC_SCHEMA = Object.freeze({
  fingerprint: 'a'.repeat(64),
  fields: Object.freeze([Object.freeze({ path: Object.freeze(['fieldparams', 'maxlength']), id: 'jform_fieldparams_maxlength', kind: 'string', maxLength: 4 })]),
});
const CREATE_SCOPE = `fd1:com_content.article:text:${'a'.repeat(64)}`;

const createBindingDescriptor = Object.freeze({
  initializationKey: 'field-create-form',
  targetId: null,
  formInstanceId: 'field-create-form',
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

const createFixture = ({
  mounted = true,
  mode = 'existing',
  createScope,
  boundTarget = null,
} = {}) => {
  const documentSource = new FakeDocument();
  const form = new EventTarget();
  Object.assign(form, { isConnected: true, id: 'item-form', contains: (node) => all.includes(node), querySelectorAll: (selector) => {
    const name = selector.match(/name="([^"]+)/)?.[1];
    return all.filter((node) => node.name !== undefined && (selector.includes('^=') ? node.name.startsWith(name) : node.name === name));
  } });
  const strings = Object.fromEntries(['title', 'name', 'label', 'description', 'default_value', 'note'].map((key) => [key, new FakeElement(FIELD_IDS[key], '')]));
  const radios = Object.fromEntries(['required', 'only_use_in_subform'].map((key) => [
    key,
    [new FakeElement(`${FIELD_IDS[key]}0`, '0'), new FakeElement(`${FIELD_IDS[key]}1`, '1')].map((element, index) => { element.name = `jform[${key}]`; element.value = String(index); return element; }),
  ]));
  const dynamic = new FakeElement('jform_fieldparams_maxlength', '100');
  dynamic.name = 'jform[fieldparams][maxlength]';
  const all = [...Object.values(strings), ...Object.values(radios).flat(), dynamic];
  documentSource.elements.set(form.id, form);
  Object.values(strings).forEach((element) => documentSource.elements.set(element.id, element));
  documentSource.elements.set(dynamic.id, dynamic);
  if (!mounted) {
    form.isConnected = false;
  }
  const options = {
    [OPTIONS_KEY]: {
      enabled: true,
      context: 'com_fields.field',
      mode,
      targetId: mode === 'create' ? null : '42',
      payloadSchemaVersion: 1,
      formId: 'item-form',
      fieldIds: { ...FIELD_IDS },
      dynamicSchema: DYNAMIC_SCHEMA,
      ...(createScope !== undefined ? { createScope } : {}),
    },
    [RUNTIME_OPTIONS_KEY]: { endpoints: { ...(mode === 'create' ? endpoints : existingEndpoints) } },
    'csrf.token': 'csrf-token',
  };
  let bindingCalls = 0;
  const bindingOptions = [];
  const runtimes = [];
  const clients = [];
  const presenters = [];
  const coordinators = [];
  const controller = new FieldAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    createBindingFactory: (configuration) => {
      bindingCalls += 1;
      bindingOptions.push(configuration);

      return {
        descriptor: () => Object.freeze({
          ...createBindingDescriptor,
          formInstanceId: configuration.lineageKey === CREATE_SCOPE ? createBindingDescriptor.formInstanceId : `${configuration.lineageKey}:form`,
          initializationKey: configuration.lineageKey === CREATE_SCOPE ? createBindingDescriptor.initializationKey : `${configuration.lineageKey}:form`,
          targetId: configuration.lineageKey === CREATE_SCOPE ? boundTarget : null,
        }),
        release: createBindingDescriptor.release,
        context: 'com_fields.field',
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
    bindingCalls: () => bindingCalls,
    bindingOptions,
    clients,
    controller,
    coordinators,
    document: documentSource,
    options,
    presenters,
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
    context: 'com_fields.field',
    payloadSchemaVersion: 1,
    formId: 'item-form',
    fieldIds: { ...FIELD_IDS },
    dynamicSchema: DYNAMIC_SCHEMA,
  };
  const create = validateConfiguration({ ...base, mode: 'create', targetId: null, createScope: CREATE_SCOPE });
  assert.equal(create.mode, 'create');
  assert.equal(create.targetId, null);
  assert.equal(create.createScope, CREATE_SCOPE);

  // A canonical target is never valid in create mode and create data is never
  // accepted for an existing record.
  assert.throws(() => validateConfiguration({ ...base, mode: 'create', targetId: '42' }), TypeError);
  assert.throws(() => validateConfiguration({ ...base, mode: 'existing', targetId: null }), TypeError);
  assert.throws(() => validateConfiguration({ ...base, mode: 'create', targetId: null, createScope: 'bad scope\n' }), TypeError);
  assert.throws(() => validateConfiguration({ ...base, mode: 'create', targetId: null, createScope: '' }), TypeError);

  // The scope-free existing contract is unchanged.
  const existing = validateConfiguration({ ...base, targetId: 42 });
  assert.equal(existing.targetId, '42');
});

test('a new Custom Field form boots one runtime with its descriptor candidate', async () => {
  const fixture = createFixture({ mode: 'create', createScope: CREATE_SCOPE });
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.bindingCalls(), 1);
  assert.equal(fixture.runtimes[0].options.context, 'com_fields.field');
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.equal(fixture.runtimes[0].options.schemaVersion, 1);
  assert.deepEqual(fixture.runtimes[0].options.createMode, createBindingDescriptor);
  assert.equal(fixture.runtimes[0].options.createScope, CREATE_SCOPE);
  assert.equal(fixture.clients[0].configuration.endpoints.initializeCreate, 'index.php?task=autosave.initializeCreate');
  assert.equal(fixture.coordinators[0].startCalls, 1);
});

test('repeated navigation events never duplicate the new Custom Field runtime', async () => {
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

test('a create descriptor change replaces both binding and runtime even when controls survive', async () => {
  const bound = `p1:${'c'.repeat(64)}`;
  const fixture = createFixture({ mode: 'create', createScope: CREATE_SCOPE, boundTarget: bound });
  fixture.controller.start();
  await fixture.controller.reconcile();

  const nextFingerprint = 'b'.repeat(64);
  const nextScope = `fd1:com_content.article:radio:${nextFingerprint}`;
  fixture.options[OPTIONS_KEY] = {
    ...fixture.options[OPTIONS_KEY],
    createScope: nextScope,
    dynamicSchema: { ...DYNAMIC_SCHEMA, fingerprint: nextFingerprint },
  };
  await fixture.controller.reconcile();

  assert.equal(fixture.bindingCalls(), 2);
  assert.deepEqual(fixture.bindingOptions, [
    { context: 'com_fields.field', lineageKey: CREATE_SCOPE },
    { context: 'com_fields.field', lineageKey: nextScope },
  ]);
  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.runtimes[1].options.targetId, null);
  assert.equal(fixture.runtimes[1].options.createScope, nextScope);
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

test('an existing Custom Field record still boots without create state', async () => {
  const fixture = createFixture({ mode: 'existing' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.bindingCalls(), 0);
  assert.equal(fixture.runtimes[0].options.targetId, '42');
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
