/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import TourAutosaveController, {
  TOUR_CANONICAL_TASK_POLICY,
  TOUR_OPTIONS_KEY,
  validateTourConfiguration,
} from '../../../../media_source/com_guidedtours/src/tour-autosave-controller.es6.js';

const fieldIds = Object.fromEntries(
  ['title', 'uid', 'description', 'note', 'url', 'autostart'].map((key) => [key, `jform_${key}`]),
);

class Element extends EventTarget {
  constructor(id, value = '') { super(); this.id = id; this.value = value; this.isConnected = true; this.checked = false; }
}

class Form extends Element {
  constructor() { super('guidedtours-form'); this.children = new Set(); }
  contains(element) { return this.children.has(element); }
  querySelectorAll() { return []; }
}

class DocumentSource extends EventTarget {
  constructor(form, fields) { super(); this.form = form; this.fields = fields; }
  getElementById(id) { return id === this.form.id ? this.form : Object.values(this.fields).flat().find((field) => field.id === id) || null; }
  querySelectorAll(selector) { return selector === '[name="jform[autostart]"]' ? this.fields.autostart : []; }
}

class Editor {
  constructor(value = '') { this.value = value; this.listeners = new Set(); }
  supportsChangeObservation() { return true; }
  getValue() { return this.value; }
  setValue(value) { this.value = value; }
  subscribeChange(listener) { this.listeners.add(listener); return () => this.listeners.delete(listener); }
}

class Runtime {
  constructor(options) { this.options = options; this.destroyCalls = 0; }
  start() { this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; }
  destroy() { if (!this.destroyCalls) { this.destroyCalls += 1; this.unsubscribe?.(); this.options.adapter.destroy(); } }
}

test('Guided Tour configuration accepts exact existing context and rejects new or extra fields', () => {
  const source = {
    enabled: true, context: 'com_guidedtours.tour', targetId: '42', payloadSchemaVersion: 1,
    formId: 'guidedtours-form', fieldIds,
  };
  assert.equal(validateTourConfiguration(source).targetId, '42');
  assert.equal(validateTourConfiguration({ enabled: false }), null);
  assert.throws(() => validateTourConfiguration({ ...source, targetId: '0' }), /target is invalid/);
  assert.throws(() => validateTourConfiguration({ ...source, context: 'com_guidedtours.step' }), /configuration is invalid/);
  assert.throws(() => validateTourConfiguration({ ...source, fieldIds: { ...fieldIds, published: 'jform_published' } }), /configuration is invalid/);
});

test('Guided Tour canonical policy matches native toolbar actions', () => {
  assert.deepEqual(TOUR_CANONICAL_TASK_POLICY['tour.apply'], { intent: 'apply', transport: 'ajax', canonical: true });
  assert.deepEqual(TOUR_CANONICAL_TASK_POLICY['tour.save2copy'], { intent: 'save-copy', transport: 'native', canonical: true });
  assert.equal(TOUR_CANONICAL_TASK_POLICY['tour.cancel'].canonical, false);
});

test('valid Guided Tour activates once and form replacement destroys the owned pair', async () => {
  const form = new Form();
  const fields = Object.fromEntries(Object.entries(fieldIds).map(([key, id]) => [key, new Element(id)]));
  fields.description.value = '<p>Tour</p>';
  fields.autostart = [new Element('jform_autostart0', '0'), new Element('jform_autostart1', '1')];
  fields.autostart[0].checked = true;
  Object.values(fields).flat().forEach((field) => form.children.add(field));
  const documentSource = new DocumentSource(form, fields);
  const editor = new Editor('<p>Tour</p>');
  const runtimes = [];
  const controller = new TourAutosaveController({
    documentSource,
    optionsReader: (key, fallback) => (key === TOUR_OPTIONS_KEY ? {
      enabled: true, context: 'com_guidedtours.tour', targetId: '42', payloadSchemaVersion: 1,
      formId: 'guidedtours-form', fieldIds,
    } : key === 'com_autosave.runtime' ? { endpoints: {
      initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x',
      prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
    } } : key === 'csrf.token' ? 'token' : fallback),
    editorRegistry: { get: () => editor, subscribeLifecycle: () => () => {} },
    apiClientFactory: () => ({}),
    runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
    presenterFactory: () => ({ start() { return this; }, destroy() {} }),
  });
  controller.start(); controller.start(); await controller.reconcile();
  assert.equal(runtimes.length, 1);
  assert.equal(runtimes[0].options.adapter.capture().description, '<p>Tour</p>');
  form.isConnected = false;
  await controller.reconcile();
  assert.equal(runtimes[0].destroyCalls, 1);
});

test('Guided Tour template reuses each shared Autosave layout exactly once', async () => {
  const template = await readFile(new URL('../../../../administrator/components/com_guidedtours/tmpl/tour/edit.php', import.meta.url), 'utf8');
  assert.equal(template.match(/joomla\.autosave\.status/g)?.length, 1);
  assert.equal(template.match(/joomla\.autosave\.recovery/g)?.length, 1);
});
