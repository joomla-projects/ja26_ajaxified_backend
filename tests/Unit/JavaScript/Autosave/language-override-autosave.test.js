import test from 'node:test';
import assert from 'node:assert/strict';
import OverrideAutosaveAdapter, { validatePayload } from '../../../../media_source/com_languages/src/override-autosave-adapter.es6.js';
import OverrideAutosaveController, { OPTIONS_KEY, validateConfiguration } from '../../../../media_source/com_languages/src/override-autosave-controller.es6.js';

const target = 'c1|18:languages.override|4:site|5:en-GB|17:COM_EXAMPLE_VALUE';

test('Language Override configuration accepts only its composite target', () => {
  const config = validateConfiguration({ enabled: true, context: 'com_languages.override', targetId: target,
    payloadSchemaVersion: 1, formId: 'override-form', fieldIds: { key: 'jform_key', override: 'jform_override', both: 'jform_both' } });
  assert.equal(config.targetId, target);
  assert.throws(() => validateConfiguration({ ...config, targetId: '42' }), TypeError);
  assert.throws(() => validateConfiguration({ ...config, targetId: `${target}|4:path` }), TypeError);
});

test('Language Override payload is exact and bounded', () => {
  assert.deepEqual(validatePayload({ key: '', override: 'Unicode Ω\n%1$s', both: true }), { key: '', override: 'Unicode Ω\n%1$s', both: true });
  assert.throws(() => validatePayload({ key: 'K', override: 'V', both: false, path: '/tmp/x' }), TypeError);
});

test('Language Override adapter captures and restores only authored fields', () => {
  const control = (value = '', checked = false) => ({ value, checked, isConnected: true, addEventListener() {}, removeEventListener() {}, dispatchEvent() {} });
  const fields = { key: control('OLD'), override: control('Old'), both: control('', false) };
  const adapter = new OverrideAutosaveAdapter({ descriptor: { context: 'com_languages.override', targetId: target, payloadSchemaVersion: 1 },
    form: { isConnected: true, contains: () => true }, fields }).initializeBaseline();
  assert.deepEqual(adapter.capture(), { key: 'OLD', override: 'Old', both: false });
  adapter.apply({ key: 'NEW', override: 'New\nvalue', both: true });
  assert.equal(fields.key.value, 'NEW');
  assert.equal(fields.override.value, 'New\nvalue');
  assert.equal(fields.both.checked, true);
});

test('eligible Language Override creates one runtime and tears down a replaced form', async () => {
  class Control extends EventTarget { constructor(id) { super(); this.id = id; this.value = ''; this.checked = false; this.isConnected = true; } }
  const form = new Control('override-form');
  const fields = { key: new Control('jform_key'), override: new Control('jform_override'), both: new Control('jform_both') };
  form.contains = (node) => Object.values(fields).includes(node); form.querySelector = () => null;
  const documentSource = new EventTarget();
  documentSource.getElementById = (id) => (id === form.id ? form : Object.values(fields).find((field) => field.id === id) || null);
  const runtimes = [];
  class Runtime { constructor(options) { this.options = options; this.destroyed = 0; } start() { return this; } destroy() { this.destroyed += 1; this.options.adapter.destroy(); } }
  const config = { enabled: true, context: 'com_languages.override', targetId: target, payloadSchemaVersion: 1,
    formId: 'override-form', fieldIds: { key: 'jform_key', override: 'jform_override', both: 'jform_both' } };
  const controller = new OverrideAutosaveController({ documentSource,
    optionsReader: (key, fallback) => (key === OPTIONS_KEY ? config : key === 'com_autosave.runtime' ? { endpoints: { initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' } } : key === 'csrf.token' ? 'token' : fallback),
    apiClientFactory: () => ({}), runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }) });
  controller.start(); controller.start(); await controller.reconcile(); assert.equal(runtimes.length, 1);
  form.isConnected = false; await controller.reconcile(); assert.equal(runtimes[0].destroyed, 1);
});
