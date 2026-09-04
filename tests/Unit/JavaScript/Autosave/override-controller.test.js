/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import OverrideAutosaveAdapter, { validatePayload } from '../../../../media_source/com_languages/src/override-autosave-adapter.es6.js';
import OverrideAutosaveController, {
  OPTIONS_KEY,
  TASK_POLICY,
  validateConfiguration,
} from '../../../../media_source/com_languages/src/override-autosave-controller.es6.js';

const composite = (key) => `c1|18:languages.override|4:site|5:en-GB|${key.length}:${key}`;
const EXISTING = composite('COM_EXISTING');
const createDescriptor = Object.freeze({
  initializationKey: 'override-create-form',
  targetId: null,
  formInstanceId: 'override-create-form',
  onInitialized: () => {},
  acquire: async () => true,
  release: () => {},
  onCanonicalSuccess: () => {},
});

class Control extends EventTarget {
  constructor(id, value = '', checked = false) {
    super();
    this.id = id;
    this.value = value;
    this.checked = checked;
    this.isConnected = true;
  }
}

const runtimeEndpoints = (createMode) => ({
  initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x',
  prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
  ...(createMode ? { initializeCreate: 'ic' } : {}),
});

const fieldIds = { key: 'jform_key', override: 'jform_override', both: 'jform_both' };

const createFixture = ({ mode = 'existing', targetId = EXISTING, createScope } = {}) => {
  const form = new Control('override-form');
  form.children = new Set();
  form.contains = (node) => form.children.has(node);
  form.querySelectorAll = () => [];
  const fields = {
    key: new Control(fieldIds.key, ''),
    override: new Control(fieldIds.override, ''),
    both: new Control(fieldIds.both, '', false),
  };
  Object.values(fields).forEach((field) => form.children.add(field));
  const documentSource = new EventTarget();
  documentSource.getElementById = (id) => (id === form.id ? form : Object.values(fields).find((field) => field.id === id) || null);
  const config = {
    enabled: true,
    context: 'com_languages.override',
    mode,
    targetId,
    payloadSchemaVersion: 1,
    formId: 'override-form',
    fieldIds: { ...fieldIds },
    ...(createScope !== undefined ? { createScope } : {}),
  };
  const runtimes = [];
  const clients = [];

  class Runtime {
    constructor(options) {
      this.options = options;
      this.destroyed = 0;
      this.state = { targetId: options.targetId };
    }

    start() {
      this.unsubscribe = this.options.adapter.subscribe(() => {});
      return this;
    }

    destroy() {
      if (!this.destroyed) {
        this.destroyed = 1;
        this.unsubscribe?.();
        this.options.adapter.destroy();
      }
    }
  }

  const controller = new OverrideAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => (key === OPTIONS_KEY ? config
      : key === 'com_autosave.runtime' ? { endpoints: runtimeEndpoints(mode === 'create') }
      : key === 'csrf.token' ? 'token' : fallback),
    createBindingFactory: () => ({
      descriptor: () => createDescriptor,
      release: createDescriptor.release,
      context: 'com_languages.override',
    }),
    apiClientFactory: (configuration) => {
      clients.push(configuration);
      return {};
    },
    runtimeFactory: (options) => {
      const runtime = new Runtime(options);
      runtimes.push(runtime);
      return runtime;
    },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
    presenterFactory: () => ({ destroy() {} }),
  });

  return {
    clients,
    config,
    controller,
    documentSource,
    fields,
    form,
    runtimes,
  };
};

test('existing Language Override configuration keeps its composite target', () => {
  const configuration = {
    enabled: true,
    context: 'com_languages.override',
    targetId: EXISTING,
    payloadSchemaVersion: 1,
    formId: 'override-form',
    fieldIds,
  };
  assert.equal(validateConfiguration(configuration).mode, 'existing');
  assert.equal(validateConfiguration(configuration).targetId, EXISTING);
  assert.throws(() => validateConfiguration({ ...configuration, targetId: '42' }), /target mode is invalid/);
  assert.throws(() => validateConfiguration({ ...configuration, targetId: composite('lower') }), /target mode is invalid/);
  assert.equal(validateConfiguration({ enabled: false }), null);

  const create = { ...configuration, mode: 'create', targetId: null, createScope: 'site|en-GB' };
  assert.equal(validateConfiguration(create).mode, 'create');
  assert.equal(validateConfiguration(create).targetId, null);
  assert.throws(() => validateConfiguration({ ...create, targetId: EXISTING }), /target mode is invalid/);
});

test('existing Language Override boots one runtime with the composite target', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, EXISTING);
  assert.equal(fixture.runtimes[0].options.createMode, null);

  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
});

test('a new Language Override activates one unresolved runtime bound to its browser lineage', async () => {
  const fixture = createFixture({ mode: 'create', targetId: null, createScope: 'site|en-GB' });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.equal(fixture.runtimes[0].options.createMode.targetId, null);
  assert.equal(fixture.runtimes[0].options.createScope, 'site|en-GB');
  assert.equal(fixture.clients[0].endpoints.initializeCreate, 'ic');

  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].destroyed, 0);
});

test('refreshed options replace a create pair with the canonical composite target', async () => {
  const fixture = createFixture({ mode: 'create', targetId: null, createScope: 'site|en-GB' });
  fixture.controller.start();
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);

  fixture.config.mode = 'existing';
  fixture.config.targetId = EXISTING;
  delete fixture.config.createScope;
  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyed, 1);
  assert.equal(fixture.runtimes[1].options.targetId, EXISTING);
  assert.equal(fixture.runtimes[1].options.createMode, null);
  assert.equal(fixture.runtimes[1].options.createScope, null);
});

test('Language Override adapter round-trips key, value and both without smuggling', () => {
  const controls = (value = '', checked = false) => ({
    value,
    checked,
    isConnected: true,
    addEventListener() {},
    removeEventListener() {},
    dispatchEvent() {},
  });
  const fields = { key: controls('OLD'), override: controls('Old'), both: controls('', false) };
  const adapter = new OverrideAutosaveAdapter({
    descriptor: { context: 'com_languages.override', targetId: null, payloadSchemaVersion: 1 },
    form: { isConnected: true, contains: () => true },
    fields,
  }).initializeBaseline();

  assert.deepEqual(adapter.capture(), { key: 'OLD', override: 'Old', both: false });
  adapter.apply({ key: 'NEW', override: 'New\nvalue', both: true });
  assert.equal(fields.key.value, 'NEW');
  assert.equal(fields.override.value, 'New\nvalue');
  assert.equal(fields.both.checked, true);
  assert.deepEqual(adapter.capture(), { key: 'NEW', override: 'New\nvalue', both: true });
  assert.throws(() => validatePayload({ key: 'K', override: 'V', both: false, path: '/tmp/x' }), TypeError);
  assert.throws(() => validatePayload({ key: 'K', override: 'V' }), TypeError);
});

test('missing Language Override fields keep the controller inactive', async () => {
  const fixture = createFixture({ mode: 'create', targetId: null });
  fixture.fields.key.isConnected = false;
  fixture.controller.start();
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 0);
});

test('Language Override task policy covers the native save actions', () => {
  assert.deepEqual(Object.keys(TASK_POLICY).sort(), [
    'override.apply', 'override.cancel', 'override.save', 'override.save2new',
  ]);
  assert.equal(TASK_POLICY['override.apply'].intent, 'apply');
  assert.equal(TASK_POLICY['override.save2new'].intent, 'save-new');
});
