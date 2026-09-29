/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import BannerAutosaveController, { FIELD_KEYS, OPTIONS_KEY, TASK_POLICY, validateConfiguration } from '../../../../media_source/com_banners/src/banner-autosave-controller.es6.js';

const fieldIds = Object.fromEntries(FIELD_KEYS.map((key) => [key, `jform_${key}`]));

class Element extends EventTarget {
  constructor(id, value = '') { super(); this.id = id; this.value = value; this.isConnected = true; this.checked = false; this.attributes = new Map(); }
  closest(selector) { return selector === 'joomla-field-media' ? this.mediaField || null : null; }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  setAttribute(name, value) { this.attributes.set(name, value); }
}

class Form extends Element {
  constructor() { super('banner-form'); this.children = new Set(); }
  contains(element) { return this.children.has(element); }
  querySelector() { return null; }
  querySelectorAll() { return []; }
}

class DocumentSource extends EventTarget {
  constructor(form, fields) { super(); this.form = form; this.fields = fields; }
  getElementById(id) { return id === this.form.id ? this.form : Object.values(this.fields).flat().find((field) => field.id === id) || null; }
  querySelectorAll(selector) { return selector === '[name="jform[own_prefix]"]' ? this.fields.own_prefix : []; }
}

class Runtime {
  constructor(options) { this.options = options; this.startCalls = 0; this.destroyCalls = 0; }
  start() { this.startCalls += 1; this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; }
  destroy() { if (!this.destroyCalls) { this.destroyCalls += 1; this.unsubscribe?.(); this.options.adapter.destroy(); } }
}

const configuration = { enabled: true, context: 'com_banners.banner', targetId: '42', payloadSchemaVersion: 2, formId: 'banner-form', fieldIds };

const fixture = () => {
  const form = new Form();
  const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, new Element(fieldIds[key], key === 'type' ? '0' : ['catid', 'cid'].includes(key) ? '1' : '')]));
  fields.own_prefix = [new Element('jform_own_prefix0', '0'), new Element('jform_own_prefix1', '1')];
  fields.own_prefix[0].checked = true;
  const mediaField = { isConnected: true, contains: (field) => field === fields.imageurl, setValue: (value) => { fields.imageurl.value = value; } };
  fields.imageurl.mediaField = mediaField;
  Object.values(fields).flat().forEach((field) => form.children.add(field)); form.children.add(mediaField);
  const editor = { supportsChangeObservation: () => true, getValue: () => '', setValue() {}, subscribeChange: () => () => {} };
  const lifecycle = new Set();
  const editorRegistry = { get: () => editor, subscribeLifecycle: (callback) => { lifecycle.add(callback); return () => lifecycle.delete(callback); } };
  return { documentSource: new DocumentSource(form, fields), editorRegistry, form };
};

test('Banner configuration and task policy enforce existing record native fallback', () => {
  assert.equal(validateConfiguration(configuration).targetId, '42');
  assert.equal(validateConfiguration({ enabled: false }), null);
  assert.throws(() => validateConfiguration({ ...configuration, targetId: '0' }), /target is invalid/);
  assert.deepEqual(TASK_POLICY['banner.apply'], { intent: 'apply', transport: 'ajax', canonical: true });
  assert.deepEqual(TASK_POLICY['banner.save2copy'], { intent: 'save-copy', transport: 'native', canonical: true });
  assert.equal(TASK_POLICY['banner.cancel'].canonical, false);
});

test('eligible Banner creates and starts one runtime then tears it down when stale', async () => {
  const { documentSource, editorRegistry, form } = fixture();
  const runtimes = [];
  const controller = new BannerAutosaveController({
    documentSource, editorRegistry,
    optionsReader: (key, fallback) => (key === OPTIONS_KEY ? configuration : key === 'com_autosave.runtime' ? { endpoints: {
      initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
    } } : key === 'csrf.token' ? 'token' : fallback),
    apiClientFactory: () => ({}),
    runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
    presenterFactory: () => ({ start() { return this; }, destroy() {} }),
  });
  controller.start(); controller.start(); await controller.reconcile();
  assert.equal(runtimes.length, 1);
  assert.equal(runtimes[0].startCalls, 1);
  form.isConnected = false; await controller.reconcile();
  assert.equal(runtimes[0].destroyCalls, 1);
});

test('missing configuration remains native fallback without a runtime', async () => {
  const { documentSource, editorRegistry } = fixture();
  let runtimeCalls = 0;
  const controller = new BannerAutosaveController({ documentSource, editorRegistry, optionsReader: () => null, runtimeFactory: () => { runtimeCalls += 1; return new Runtime({}); } });
  controller.start(); await controller.reconcile();
  assert.equal(runtimeCalls, 0);
});

test('new Banner without a category or client still boots the unresolved create runtime', async () => {
  const form = new Form();
  const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, new Element(fieldIds[key], '')]));
  fields.type.value = '0';
  fields.own_prefix = [new Element('jform_own_prefix0', '0'), new Element('jform_own_prefix1', '1')];
  fields.own_prefix[0].checked = true;
  const mediaField = { isConnected: true, contains: (field) => field === fields.imageurl, setValue: (value) => { fields.imageurl.value = value; } };
  fields.imageurl.mediaField = mediaField;
  Object.values(fields).flat().forEach((field) => form.children.add(field)); form.children.add(mediaField);
  const editor = { supportsChangeObservation: () => true, getValue: () => '', setValue() {}, subscribeChange: () => () => {} };
  const editorRegistry = { get: () => editor, subscribeLifecycle: () => () => {} };
  const createConfiguration = {
    enabled: true, context: 'com_banners.banner', mode: 'create', targetId: null, payloadSchemaVersion: 2,
    formId: 'banner-form', fieldIds, locale: 'en-GB', timeZone: 'UTC',
  };
  const runtimes = [];
  const controller = new BannerAutosaveController({
    documentSource: new DocumentSource(form, fields), editorRegistry,
    optionsReader: (key, fallback) => (key === OPTIONS_KEY ? createConfiguration : key === 'com_autosave.runtime' ? { endpoints: {
      initializeCreate: 'ic', initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
    } } : key === 'csrf.token' ? 'token' : fallback),
    apiClientFactory: () => ({}),
    runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
    presenterFactory: () => ({ start() { return this; }, destroy() {} }),
  });
  controller.start(); await controller.reconcile();
  assert.equal(runtimes.length, 1);
  assert.equal(runtimes[0].startCalls, 1);
  assert.equal(runtimes[0].options.targetId, null);
  assert.equal(runtimes[0].options.adapter.baseline, null);
});

test('Banner entrypoint and template activate the controller and generic UI', async () => {
  const entry = await readFile(new URL('../../../../media_source/com_banners/js/banner-autosave.es6.js', import.meta.url), 'utf8');
  const template = await readFile(new URL('../../../../administrator/components/com_banners/tmpl/banner/edit.php', import.meta.url), 'utf8');
  assert.match(entry, /new BannerAutosaveController\(\)/);
  assert.match(entry, /controller\.start\(\)/);
  assert.equal(template.match(/joomla\.autosave\.status/g)?.length, 1);
  assert.equal(template.match(/joomla\.autosave\.recovery/g)?.length, 1);
});
