/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ContactAutosaveAdapter, { PAYLOAD_KEYS, STRING_KEYS, validatePayload } from '../../../../media_source/com_contact/src/contact-autosave-adapter.es6.js';
import ContactAutosaveController, { FIELD_KEYS, OPTIONS_KEY, TASK_POLICY, validateConfiguration } from '../../../../media_source/com_contact/src/contact-autosave-controller.es6.js';

class Control extends EventTarget {
  constructor(id, value = '') { super(); this.id = id; this.value = value; this.isConnected = true; this.attributes = new Map(); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  setAttribute(name, value) { this.attributes.set(name, value); }
  closest(selector) { return selector === 'joomla-field-media' ? this.mediaField || null : null; }
}

const payload = () => Object.fromEntries(STRING_KEYS.map((key) => [key, '']));

const adapterFixture = () => {
  const source = {
    ...payload(), name: 'Person', email_to: 'unfinished@', telephone: '+44 (', address: '12 Example Street',
    misc: '<p>Biography</p>', image: 'images/contact.jpg', publish_up: 'Tomorrow',
    publish_up_alt: '2026-08-27 00:00:00', webpage: 'https://example.test', catid: '3',
  };
  const fields = Object.fromEntries(FIELD_KEYS.filter((key) => key !== 'misc').map((key) => [key, new Control(`jform_${key}`, source[key])]));
  fields.misc = new Control('jform_misc');
  fields.publish_up.setAttribute('data-alt-value', source.publish_up_alt);
  fields.publish_down.setAttribute('data-alt-value', source.publish_down_alt);
  const controls = new Set(Object.values(fields));
  const mediaField = { isConnected: true, contains: (field) => field === fields.image, setValue: (value) => { fields.image.value = value; fields.image.dispatchEvent(new Event('change')); } };
  const form = { isConnected: true, contains: (value) => controls.has(value) || value === mediaField };
  let editorValue = source.misc;
  let editorCallback;
  const editor = {
    getValue: () => editorValue, setValue: (value) => { editorValue = value; },
    subscribeChange: (callback) => { editorCallback = callback; return () => { editorCallback = null; }; },
  };
  const adapter = new ContactAutosaveAdapter({ descriptor: { context: 'com_contact.contact', targetId: '42', payloadSchemaVersion: 2 }, form, fields, editor, getCurrentEditor: () => editor, mediaField });
  return { adapter, fields, setEditor: (value) => { editorValue = value; editorCallback?.(); }, source };
};

test('Contact captures the exact privacy-minimized contract including incomplete PII', () => {
  const { adapter, source } = adapterFixture();
  assert.deepEqual(adapter.capture(), { ...source, catid: 3 });
  assert.deepEqual(Object.keys(adapter.capture()), PAYLOAD_KEYS);
  assert.equal(Object.hasOwn(adapter.capture(), 'user_id'), false);
  assert.equal(Object.hasOwn(adapter.capture(), 'published'), false);
  assert.equal(Object.hasOwn(adapter.capture(), 'params'), false);
  assert.equal(Object.hasOwn(adapter.capture(), 'com_fields'), false);
});

test('Contact restore uses editor, calendar, media, and category APIs without changing unrelated controls', () => {
  const { adapter, fields, source } = adapterFixture();
  const unrelated = new Control('jform_access', '1');
  const restored = { ...source, email_to: '', telephone: '', misc: '<p>Restored</p>', image: '', publish_up: '', publish_up_alt: '', catid: 7 };
  adapter.initializeBaseline().apply(restored);
  assert.deepEqual(adapter.capture(), { ...restored, catid: 7 });
  assert.equal(fields.publish_up.getAttribute('data-alt-value'), '');
  assert.equal(fields.catid.value, '7');
  assert.equal(unrelated.value, '1');
});

test('Contact dirty observation covers PII, editor, and category while teardown is idempotent', () => {
  const { adapter, fields, setEditor } = adapterFixture();
  let dirty = 0;
  adapter.initializeBaseline().subscribe(() => { dirty += 1; });
  fields.email_to.value = 'partial@'; fields.email_to.dispatchEvent(new Event('input'));
  fields.mobile.value = '+'; fields.mobile.dispatchEvent(new Event('change'));
  fields.catid.value = '4'; fields.catid.dispatchEvent(new Event('change'));
  setEditor('<p>Changed</p>');
  assert.equal(dirty, 4);
  adapter.destroy(); adapter.destroy(); fields.email_to.dispatchEvent(new Event('input'));
  assert.equal(dirty, 4);
});

test('Contact rejects extra, dynamic, malformed, over-bound, and transient media payloads', () => {
  const source = payload();
  const missing = { ...source }; delete missing.email_to;
  for (const invalid of [
    missing, { ...source, password: 'secret' }, { ...source, params: {} }, { ...source, email_to: [] },
    { ...source, suburb: 'x'.repeat(101) }, { ...source, image: 'blob:temporary' },
    { ...source, user_id: 7 }, { ...source, published: 1 },
  ]) assert.throws(() => validatePayload(invalid), /payload is invalid/);
});

test('Contact category is a required bounded integer relation', () => {
  const valid = { ...payload(), catid: 3 };
  assert.deepEqual(validatePayload(valid), valid);

  for (const invalid of [
    payload(),
    { ...payload(), catid: 0 },
    { ...payload(), catid: -1 },
    { ...payload(), catid: '3' },
    { ...payload(), catid: 2147483648 },
  ]) assert.throws(() => validatePayload(invalid), /payload is invalid/);
});

const fieldIds = Object.fromEntries(FIELD_KEYS.map((key) => [key, `jform_${key}`]));

test('Contact configuration enforces exact modes, IDs, and canonical actions', () => {
  const config = { enabled: true, context: 'com_contact.contact', targetId: '42', payloadSchemaVersion: 2, formId: 'contact-form', fieldIds };
  assert.equal(validateConfiguration(config).mode, 'existing');
  assert.equal(validateConfiguration(config).targetId, '42');
  assert.equal(validateConfiguration({ enabled: false }), null);
  assert.throws(() => validateConfiguration({ ...config, targetId: '0' }), /target is invalid/);
  assert.throws(() => validateConfiguration({ ...config, mode: 'existing', targetId: null }), /target is invalid/);

  const create = { ...config, mode: 'create', targetId: null };
  assert.equal(validateConfiguration(create).mode, 'create');
  assert.equal(validateConfiguration(create).targetId, null);
  assert.throws(() => validateConfiguration({ ...config, mode: 'create', targetId: '42' }), /target mode is invalid/);
  assert.deepEqual(TASK_POLICY['contact.apply'], { intent: 'apply', transport: 'ajax', canonical: true });
  assert.deepEqual(TASK_POLICY['contact.save2menu'], { intent: 'save-exit', transport: 'native', canonical: true });
  assert.equal(TASK_POLICY['contact.cancel'].canonical, false);
});

const runtimeEndpoints = (createMode) => ({
  initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x',
  prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
  ...(createMode ? { initializeCreate: 'ic' } : {}),
});

const controllerFixture = ({ mode = 'existing', targetId = '42' } = {}) => {
  const form = new Control('contact-form'); form.children = new Set(); form.contains = (value) => form.children.has(value); form.querySelector = () => null;
  const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, new Control(fieldIds[key], key === 'catid' ? '3' : '')]));
  const mediaField = { isConnected: true, contains: (field) => field === fields.image, setValue: (value) => { fields.image.value = value; } };
  fields.image.mediaField = mediaField; Object.values(fields).forEach((field) => form.children.add(field)); form.children.add(mediaField);
  const editor = { supportsChangeObservation: () => true, getValue: () => '', setValue() {}, subscribeChange: () => () => {} };
  const documentSource = new EventTarget();
  documentSource.getElementById = (id) => (id === form.id ? form : Object.values(fields).find((field) => field.id === id) || null);
  const runtimes = [];
  class Runtime { constructor(options) { this.options = options; this.destroyed = 0; this.state = { targetId: options.targetId }; } start() { this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; } destroy() { if (!this.destroyed) { this.destroyed = 1; this.unsubscribe?.(); this.options.adapter.destroy(); } } }
  const config = { enabled: true, context: 'com_contact.contact', targetId, mode, payloadSchemaVersion: 2, formId: 'contact-form', fieldIds };
  return { config, controller: new ContactAutosaveController({
    documentSource, editorRegistry: { get: () => editor, subscribeLifecycle: () => () => {} },
    optionsReader: (key, fallback) => (key === OPTIONS_KEY ? config
      : key === 'com_autosave.runtime' ? { endpoints: runtimeEndpoints(mode === 'create') }
      : key === 'csrf.token' ? 'token' : fallback),
    createBindingFactory: () => ({
      descriptor: () => ({ initializationKey: 'contact-create', targetId: null, formInstanceId: 'contact-create', onInitialized: () => {}, acquire: async () => true, release: () => {}, onCanonicalSuccess: () => {} }),
      release: () => {}, context: 'com_contact.contact',
    }),
    apiClientFactory: () => ({}), runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }),
  }), documentSource, fields, form, runtimes };
};

test('eligible Contact activates one runtime and stale form teardown remains safe', async () => {
  const fixture = controllerFixture();
  fixture.controller.start(); fixture.controller.start(); await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, '42');
  fixture.form.isConnected = false; await fixture.controller.reconcile(); assert.equal(fixture.runtimes[0].destroyed, 1);
});

test('a new Contact activates one unresolved runtime bound to its browser lineage', async () => {
  const fixture = controllerFixture({ mode: 'create', targetId: null });
  fixture.controller.start(); await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.equal(fixture.runtimes[0].options.createMode.targetId, null);

  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].destroyed, 0);
});

test('refreshed options replace a create pair with the canonical existing target', async () => {
  const fixture = controllerFixture({ mode: 'create', targetId: null });
  fixture.controller.start(); await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);

  fixture.config.mode = 'existing';
  fixture.config.targetId = '42';
  fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyed, 1);
  assert.equal(fixture.runtimes[1].options.targetId, '42');
  assert.equal(fixture.runtimes[1].options.createMode, null);
});

test('missing Contact configuration stays native and entrypoint starts the controller', async () => {
  let runtimes = 0;
  const controller = new ContactAutosaveController({ documentSource: new EventTarget(), optionsReader: () => null, editorRegistry: { subscribeLifecycle: () => () => {} }, runtimeFactory: () => { runtimes += 1; } });
  controller.start(); await controller.reconcile(); assert.equal(runtimes, 0);
  const entry = await readFile(new URL('../../../../media_source/com_contact/js/contact-autosave.es6.js', import.meta.url), 'utf8');
  assert.match(entry, /new ContactAutosaveController\(\)/); assert.match(entry, /controller\.start\(\)/);
});
