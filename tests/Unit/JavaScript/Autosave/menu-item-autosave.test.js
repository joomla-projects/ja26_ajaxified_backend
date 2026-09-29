import assert from 'node:assert/strict';
import test from 'node:test';
import ItemAutosaveAdapter from '../../../../media_source/com_menus/src/item-autosave-adapter.es6.js';
import ItemAutosaveController, { OPTIONS_KEY, validateConfiguration } from '../../../../media_source/com_menus/src/item-autosave-controller.es6.js';

const control = (value = '') => ({ value, checked: false, isConnected: true, addEventListener() {}, removeEventListener() {}, dispatchEvent() {} });

test('Menu Item configuration is existing-record and route-schema exact', () => {
  const schema = { fingerprint: 'a'.repeat(64), fields: [{ path: ['params', 'page_title'], id: 'jform_params_page_title', kind: 'string', maxLength: 255 }] };
  assert.equal(validateConfiguration({ enabled: true, context: 'com_menus.item', targetId: 42, payloadSchemaVersion: 1, formId: 'item-form', fieldIds: {}, dynamicSchema: schema }).targetId, '42');
  assert.throws(() => validateConfiguration({ enabled: true, context: 'com_menus.item', targetId: 0, payloadSchemaVersion: 1, formId: 'item-form', fieldIds: {}, dynamicSchema: schema }));
});

test('Menu Item adapter captures only static and authoritative routed fields', () => {
  const staticFields = { title: control('Title'), alias: control('alias'), note: control('note'), browserNav: control('0') };
  const dynamicFields = { page_title: [control('Page')] };
  const form = { isConnected: true, contains: () => true };
  const adapter = new ItemAutosaveAdapter({ descriptor: { context: 'com_menus.item', targetId: '42', payloadSchemaVersion: 1 }, form, staticFields, dynamicFields, schema: { fingerprint: 'a'.repeat(64), fields: [{ path: ['params', 'page_title'], id: 'jform_params_page_title', kind: 'string', maxLength: 255 }] } });
  assert.deepEqual(adapter.capture(), { title: 'Title', alias: 'alias', note: 'note', browserNav: '0', schemaFingerprint: 'a'.repeat(64), params: { page_title: 'Page' } });
});

test('production Menu Item wiring creates one runtime from the emitted view contract', async () => {
  class Control extends EventTarget {
    constructor(id, name, value = '') { super(); this.id = id; this.name = name; this.value = value; this.checked = false; this.isConnected = true; }
  }

  const schema = { fingerprint: 'a'.repeat(64), fields: [{ path: ['params', 'page_title'], id: 'jform_params_page_title', kind: 'string', maxLength: 255 }] };
  const fieldIds = { title: 'jform_title', alias: 'jform_alias', note: 'jform_note', browserNav: 'jform_browserNav' };
  const fields = Object.fromEntries(Object.entries(fieldIds).map(([key, id]) => [key, new Control(id, `jform[${key}]`, key)]));
  const routed = new Control('jform_params_page_title', 'jform[params][page_title]', 'Page title');
  const controls = [...Object.values(fields), routed];
  const form = new Control('item-form', 'adminForm');
  form.contains = (node) => controls.includes(node);
  form.querySelectorAll = (selector) => (selector === '[name="jform[params][page_title]"]' ? [routed] : []);
  const documentSource = new EventTarget();
  documentSource.getElementById = (id) => (id === form.id ? form : controls.find((node) => node.id === id) || null);
  const configuration = { enabled: true, context: 'com_menus.item', targetId: '42', payloadSchemaVersion: 1, formId: 'item-form', fieldIds, locale: 'en-GB', timeZone: 'UTC', dynamicSchema: schema };
  const endpoints = { initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' };
  let runtimes = 0;
  class Runtime {
    constructor(options) { this.options = options; }
    start() { runtimes += 1; return this; }
    destroy() { this.options.adapter.destroy(); }
  }
  const optionsReader = (key, fallback) => (key === OPTIONS_KEY ? configuration : key === 'com_autosave.runtime' ? { endpoints } : key === 'csrf.token' ? 'token' : fallback);
  const controller = new ItemAutosaveController({ documentSource, optionsReader, apiClientFactory: () => ({}), runtimeFactory: (options) => new Runtime(options), coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }) });

  controller.start();
  await new Promise((resolve) => setImmediate(resolve));
  controller.start();
  await controller.reconcile();
  assert.equal(runtimes, 1);

  documentSource.dispatchEvent(new Event('joomla:updated'));
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(runtimes, 1);
});
