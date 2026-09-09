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

test('option rows are captured live and recovery reconciles row count and associations', async () => {
  const rowSchema = { fingerprint: 'b'.repeat(64), fields: [{ path: ['fieldparams', 'options'], id: 'jform_fieldparams_options', kind: 'rows', maxItems: 50, columns: { name: 255, value: 255 } }] };
  class RowControl extends EventTarget {
    constructor(host, name, value) { super(); this.host = host; this.name = name; this.value = value; this.isConnected = true; }
    closest(selector) { return selector === 'joomla-field-subform' ? this.host : null; }
  }
  class Row {
    constructor(host, index, name = '', value = '') { this.controls = [new RowControl(host, `jform[fieldparams][options][options${index}][name]`, name), new RowControl(host, `jform[fieldparams][options][options${index}][value]`, value)]; }
    querySelectorAll() { return this.controls; }
  }
  class Host extends EventTarget {
    constructor() { super(); this.rows = []; this.isConnected = true; this.next = 0; }
    getRows() { return [...this.rows]; }
    add(name = '', value = '') { const row = new Row(this, this.next++, name, value); this.rows.push(row); return row; }
    addRow() { const row = this.add(); this.dispatchEvent(new Event('subform-row-add')); return row; }
    removeRow(row) { this.dispatchEvent(new Event('subform-row-remove')); this.rows.splice(this.rows.indexOf(row), 1); }
  }
  const host = new Host();
  host.add('First', '0');
  host.add('', '');
  const staticFields = { title: control('Title'), name: control('name'), label: control('Label'), description: control(''), default_value: control(''), note: control(''), required: [control('0'), control('1')], only_use_in_subform: [control('0'), control('1')] };
  staticFields.required[0].checked = true;
  staticFields.only_use_in_subform[0].checked = true;
  const form = new EventTarget();
  form.isConnected = true;
  form.contains = (node) => node === host || Object.values(staticFields).flat().includes(node) || host.rows.some((row) => row.controls.includes(node));
  const adapter = new FieldAutosaveAdapter({ descriptor: { context: 'com_fields.field', targetId: '42', payloadSchemaVersion: 1 }, form, staticFields, dynamicFields: { options: host }, schema: rowSchema }).initializeBaseline();

  assert.deepEqual(adapter.capture().fieldparams.options, [{ name: 'First', value: '0' }, { name: '', value: '' }]);
  let changes = 0;
  const unsubscribe = adapter.subscribe(() => { changes += 1; });
  const added = host.addRow();
  added.controls[0].value = 'Third';
  form.dispatchEvent(new Event('input'));
  await Promise.resolve();
  assert.equal(changes > 0, true);
  assert.deepEqual(adapter.capture().fieldparams.options, [{ name: 'First', value: '0' }, { name: '', value: '' }, { name: 'Third', value: '' }]);

  host.rows = [host.rows[2], host.rows[0], host.rows[1]];
  host.dispatchEvent(new Event('subform-order-changed'));
  await Promise.resolve();
  assert.deepEqual(adapter.capture().fieldparams.options.map((row) => row.name), ['Third', 'First', '']);
  host.removeRow(host.rows[1]);
  await Promise.resolve();
  assert.deepEqual(adapter.capture().fieldparams.options.map((row) => row.name), ['Third', '']);

  const payload = adapter.capture();
  adapter.apply({ ...payload, fieldparams: { options: [{ name: 'Zero', value: '0' }] } });
  assert.deepEqual(adapter.capture().fieldparams.options, [{ name: 'Zero', value: '0' }]);
  adapter.apply({ ...payload, fieldparams: { options: [] } });
  assert.deepEqual(adapter.capture().fieldparams.options, []);
  unsubscribe();
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

test('fresh and existing List, Radio and Checkboxes resolve their native option host', async (t) => {
  for (const mode of ['create', 'existing']) {
    for (const type of ['list', 'radio', 'checkboxes']) {
      await t.test(`${mode} ${type}`, async () => {
        class Node extends EventTarget {
          constructor(id, name = '', value = '') { super(); this.id = id; this.name = name; this.value = value; this.checked = false; this.isConnected = true; }
          closest(selector) { return selector === 'joomla-field-subform' ? this.host || null : null; }
        }
        const host = new Node('', 'jform[fieldparams][options]');
        const row = { querySelectorAll: () => row.controls };
        row.controls = [new Node('', 'jform[fieldparams][options][options0][name]', 'Label'), new Node('', 'jform[fieldparams][options][options0][value]', '0')];
        row.controls.forEach((item) => { item.host = host; });
        host.rows = [row];
        host.getRows = () => [...host.rows];
        host.addRow = () => null;
        host.removeRow = () => {};
        const form = new Node('item-form');
        const fieldIds = Object.fromEntries(['title', 'name', 'label', 'description', 'default_value', 'note', 'required', 'only_use_in_subform'].map((key) => [key, `jform_${key}`]));
        const strings = Object.fromEntries(['title', 'name', 'label', 'description', 'default_value', 'note'].map((key) => [key, new Node(fieldIds[key], `jform[${key}]`, key === 'title' ? 'Title' : '')]));
        const booleans = Object.fromEntries(['required', 'only_use_in_subform'].map((key) => [key, [new Node(`${fieldIds[key]}0`, `jform[${key}]`, '0'), new Node(`${fieldIds[key]}1`, `jform[${key}]`, '1')]]));
        booleans.required[0].checked = true;
        booleans.only_use_in_subform[0].checked = true;
        const all = [...Object.values(strings), ...Object.values(booleans).flat(), host, ...row.controls];
        form.contains = (candidate) => all.includes(candidate);
        form.querySelectorAll = (selector) => {
          if (selector.startsWith('joomla-field-subform')) return [host];
          const name = selector.match(/name="([^"]+)/)?.[1];
          return all.filter((candidate) => candidate.name === name);
        };
        const documentSource = new EventTarget();
        documentSource.getElementById = (id) => (id === form.id ? form : all.find((candidate) => candidate.id === id) || null);
        const fingerprint = ({ list: 'c', radio: 'd', checkboxes: 'e' })[type].repeat(64);
        const rowTypeSchema = { fingerprint, fields: [{ path: ['fieldparams', 'options'], id: 'jform_fieldparams_options', kind: 'rows', maxItems: 50, columns: { name: 255, value: 255 } }] };
        const configuration = { enabled: true, context: 'com_fields.field', mode, targetId: mode === 'create' ? null : '42', payloadSchemaVersion: 1, formId: form.id, fieldIds, dynamicSchema: rowTypeSchema, ...(mode === 'create' ? { createScope: `fd1:com_content.article:${type}:${fingerprint}` } : {}) };
        const captures = [];
        const controller = new FieldAutosaveController({
          documentSource,
          optionsReader: (key, fallback) => (key === OPTIONS_KEY ? configuration : key === 'com_autosave.runtime' ? { endpoints: { initialize: 'i', initializeCreate: 'ic', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' } } : key === 'csrf.token' ? 'token' : fallback),
          createBindingFactory: () => ({ descriptor: () => ({ initializationKey: 'form', targetId: null, formInstanceId: 'form', onInitialized() {}, async acquire() { return true; }, release() {}, onCanonicalSuccess() {} }) }),
          apiClientFactory: () => ({}),
          runtimeFactory: (options) => ({ options, state: { targetId: options.targetId, canonicalAction: null, status: 'clean' }, start() { options.adapter.subscribe(() => {}); captures.push(options.adapter.capture()); return this; }, destroy() { options.adapter.destroy(); } }),
          coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
          presenterFactory: () => ({ destroy() {} }),
        });
        controller.start();
        await new Promise((resolve) => setImmediate(resolve));

        assert.equal(captures.length, 1);
        assert.equal(captures[0].title, 'Title');
        assert.deepEqual(captures[0].fieldparams.options, [{ name: 'Label', value: '0' }]);
        controller.destroy();
      });
    }
  }
});
