import test from 'node:test';
import assert from 'node:assert/strict';
import FieldAutosaveAdapter, { validateSchema } from '../../../../media_source/com_fields/src/field-autosave-adapter.es6.js';
import FieldAutosaveController, { OPTIONS_KEY, validateConfiguration } from '../../../../media_source/com_fields/src/field-autosave-controller.es6.js';

const schema = { fingerprint: 'a'.repeat(64), fields: [{ path: ['fieldparams', 'maxlength'], id: 'jform_fieldparams_maxlength', kind: 'string', maxLength: 4 }] };
const control = (value = '') => ({ value, checked: value === '1', isConnected: true, addEventListener() {}, removeEventListener() {}, dispatchEvent() {} });

test('server schema is bounded and rejects prototype paths', () => {
  assert.equal(validateSchema(schema).fields.length, 1);
  assert.throws(() => validateSchema({ ...schema, fields: [{ ...schema.fields[0], path: ['fieldparams', '__proto__'] }] }), TypeError);
});

test('configuration requires the exact existing Custom Field context', () => {
  const fieldIds = Object.fromEntries(['title', 'name', 'label', 'description', 'default_value', 'note', 'required', 'only_use_in_subform'].map((key) => [key, `jform_${key}`]));
  const config = validateConfiguration({ enabled: true, context: 'com_fields.field', targetId: 42, payloadSchemaVersion: 1, formId: 'item-form', fieldIds, dynamicSchema: schema });
  assert.equal(config.targetId, '42');
  assert.throws(() => validateConfiguration({ ...config, targetId: 0 }), TypeError);
});

test('adapter captures and restores only schema-approved values', () => {
  const staticFields = { title: control('Title'), name: control('name'), label: control('Label'), description: control('Description'), default_value: control('Default'), note: control('Note'), required: [control('0'), control('1')], only_use_in_subform: [control('0'), control('1')] };
  staticFields.required[0].checked = true; staticFields.required[1].checked = false; staticFields.only_use_in_subform[0].checked = true; staticFields.only_use_in_subform[1].checked = false;
  const dynamicFields = { maxlength: [control('100')] };
  const all = [...Object.values(staticFields).flat(), ...Object.values(dynamicFields).flat()];
  const adapter = new FieldAutosaveAdapter({ descriptor: { context: 'com_fields.field', targetId: '42', payloadSchemaVersion: 1 }, form: { isConnected: true, contains: (node) => all.includes(node) }, staticFields, dynamicFields, schema }).initializeBaseline();
  const payload = adapter.capture();
  assert.deepEqual(Object.keys(payload), ['title', 'name', 'label', 'description', 'default_value', 'note', 'required', 'only_use_in_subform', 'schemaFingerprint', 'fieldparams']);
  assert.deepEqual({ ...payload.fieldparams }, { maxlength: '100' });
  assert.equal(payload.query, undefined);
  adapter.apply({ ...payload, title: 'Restored', fieldparams: { maxlength: '255' } });
  assert.equal(staticFields.title.value, 'Restored');
  assert.equal(dynamicFields.maxlength[0].value, '255');
});

test('production controller activates one runtime for the real form contract', async () => {
  class Control extends EventTarget { constructor(id, name = '', value = '') { super(); this.id = id; this.name = name; this.value = value; this.checked = false; this.isConnected = true; } }
  const form = new Control('item-form');
  const fieldIds = Object.fromEntries(['title', 'name', 'label', 'description', 'default_value', 'note', 'required', 'only_use_in_subform'].map((key) => [key, `jform_${key}`]));
  const strings = Object.fromEntries(['title', 'name', 'label', 'description', 'default_value', 'note'].map((key) => [key, new Control(fieldIds[key], `jform[${key}]`)]));
  const radios = Object.fromEntries(['required', 'only_use_in_subform'].map((key) => [key, [new Control(`${fieldIds[key]}0`, `jform[${key}]`, '0'), new Control(`${fieldIds[key]}1`, `jform[${key}]`, '1')]]));
  const dynamic = new Control('jform_fieldparams_maxlength', 'jform[fieldparams][maxlength]', '100');
  const all = [...Object.values(strings), ...Object.values(radios).flat(), dynamic]; form.contains = (node) => all.includes(node); form.querySelector = () => null;
  form.querySelectorAll = (selector) => { const name = selector.match(/name="([^"]+)/)?.[1]; return all.filter((node) => selector.includes('^=') ? node.name.startsWith(name) : node.name === name); };
  assert.equal(form.querySelectorAll('[name="jform[required]"]').length, 2);
  assert.equal(form.querySelectorAll('[name="jform[fieldparams][maxlength]"]').length, 1);
  const documentSource = new EventTarget(); documentSource.getElementById = (id) => (id === form.id ? form : all.find((node) => node.id === id) || null);
  const config = { enabled: true, context: 'com_fields.field', targetId: 42, payloadSchemaVersion: 1, formId: 'item-form', fieldIds, dynamicSchema: schema };
  const runtimes = [];
  class Runtime { constructor(options) { this.options = options; } start() { runtimes.push(this); return this; } destroy() { this.options.adapter.destroy(); } }
  const controller = new FieldAutosaveController({ documentSource, optionsReader: (key, fallback) => (key === OPTIONS_KEY ? config : key === 'com_autosave.runtime' ? { endpoints: { initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' } } : key === 'csrf.token' ? 'token' : fallback), apiClientFactory: () => ({}), runtimeFactory: (options) => new Runtime(options), coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }) });
  controller.start(); await new Promise((resolve) => setImmediate(resolve)); controller.start(); await controller.reconcile();
  assert.equal(runtimes.length, 1);
});
