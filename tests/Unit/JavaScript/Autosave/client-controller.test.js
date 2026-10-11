/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ClientAutosaveController, { CLIENT_CANONICAL_TASK_POLICY, CLIENT_OPTIONS_KEY, validateClientConfiguration } from '../../../../media_source/com_banners/src/client-autosave-controller.es6.js';

const fieldIds = Object.fromEntries([
  'name', 'contact', 'email', 'extrainfo', 'metakey', 'metakey_prefix', 'version_note',
  'purchase_type', 'track_impressions', 'track_clicks', 'own_prefix',
].map((key) => [key, `jform_${key}`]));

class Element extends EventTarget {
  constructor(id, value = '') { super(); this.id = id; this.value = value; this.isConnected = true; this.checked = false; }
}

class Form extends Element {
  constructor() { super('client-form'); this.children = new Set(); }
  contains(element) { return this.children.has(element); }
  querySelectorAll() { return []; }
}

class DocumentSource extends EventTarget {
  constructor(form, fields) { super(); this.form = form; this.fields = fields; }
  getElementById(id) { return id === this.form.id ? this.form : Object.values(this.fields).flat().find((field) => field.id === id) || null; }
  querySelectorAll(selector) { return selector === '[name="jform[own_prefix]"]' ? this.fields.own_prefix : []; }
}

class Runtime {
  constructor(options) { this.options = options; this.destroyCalls = 0; }
  start() { this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; }
  destroy() { if (!this.destroyCalls) { this.destroyCalls += 1; this.unsubscribe?.(); this.options.adapter.destroy(); } }
}

test('Banner Client configuration accepts an existing target and rejects new or incomplete pages', () => {
  const source = { enabled: true, context: 'com_banners.client', targetId: '42', payloadSchemaVersion: 1, formId: 'client-form', fieldIds };
  const valid = validateClientConfiguration(source);
  assert.equal(valid.targetId, '42');
  assert.equal(validateClientConfiguration({ enabled: false }), null);
  assert.throws(() => validateClientConfiguration({ ...source, targetId: '0' }), /target is invalid/);
  const incomplete = { ...fieldIds }; delete incomplete.email;
  assert.throws(() => validateClientConfiguration({ ...source, fieldIds: incomplete }), /configuration is invalid/);
});

test('Banner Client canonical task policy matches Joomla Client toolbar actions', () => {
  assert.deepEqual(CLIENT_CANONICAL_TASK_POLICY['client.apply'], { intent: 'apply', transport: 'ajax', canonical: true });
  assert.deepEqual(CLIENT_CANONICAL_TASK_POLICY['client.save2copy'], { intent: 'save-copy', transport: 'native', canonical: true });
  assert.equal(CLIENT_CANONICAL_TASK_POLICY['client.cancel'].canonical, false);
});

test('valid existing Banner Client activates one generic pair and replacement destroys it', async () => {
  const form = new Form();
  const fields = Object.fromEntries(Object.entries(fieldIds).map(([key, id]) => [key, new Element(id, '0')]));
  fields.name.value = 'Client'; fields.own_prefix = [new Element('jform_own_prefix0', '0'), new Element('jform_own_prefix1', '1')];
  fields.own_prefix[0].checked = true;
  Object.values(fields).flat().forEach((field) => form.children.add(field));
  const documentSource = new DocumentSource(form, fields);
  const runtimes = [];
  const controller = new ClientAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => (key === CLIENT_OPTIONS_KEY ? {
      enabled: true, context: 'com_banners.client', targetId: '42', payloadSchemaVersion: 1, formId: 'client-form', fieldIds,
    } : key === 'com_autosave.runtime' ? { endpoints: {
      initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
    } } : key === 'csrf.token' ? 'token' : fallback),
    apiClientFactory: () => ({}),
    runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
    presenterFactory: () => ({ start() { return this; }, destroy() {} }),
  });
  controller.start(); controller.start(); await controller.reconcile();
  assert.equal(runtimes.length, 1);
  assert.equal(runtimes[0].options.adapter.capture().name, 'Client');

  form.isConnected = false;
  await controller.reconcile();
  assert.equal(runtimes[0].destroyCalls, 1);
});

test('Banner Client template reuses generic recovery and status UI before the edit fields', async () => {
  const template = await readFile(new URL('../../../../administrator/components/com_banners/tmpl/client/edit.php', import.meta.url), 'utf8');
  assert.equal(template.match(/joomla\.autosave\.status/g)?.length, 1);
  assert.equal(template.match(/joomla\.autosave\.recovery/g)?.length, 1);
  assert.ok(template.indexOf('COM_BANNERS_CLIENT_AUTOSAVE_SCOPE_NOTICE') < template.indexOf("renderField('contact')"));
});
