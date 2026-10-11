/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import StepAutosaveController, { STEP_CANONICAL_TASK_POLICY, STEP_OPTIONS_KEY, validateStepConfiguration } from '../../../../media_source/com_guidedtours/src/step-autosave-controller.es6.js';

const fieldIds = Object.fromEntries(['position', 'target', 'title', 'description', 'type', 'url', 'interactive_type', 'note', 'required', 'requiredvalue'].map((key) => [key, key === 'required' ? 'jform_params_required' : key === 'requiredvalue' ? 'jform_params_requiredvalue' : `jform_${key}`]));
class Element extends EventTarget { constructor(id, value = '') { super(); this.id = id; this.value = value; this.checked = false; this.isConnected = true; } }
class Form extends Element { constructor() { super('guidedtour-dates-form'); this.children = new Set(); } contains(element) { return this.children.has(element); } querySelectorAll() { return []; } }
class DocumentSource extends EventTarget { constructor(form, fields) { super(); this.form = form; this.fields = fields; } getElementById(id) { return id === this.form.id ? this.form : Object.values(this.fields).flat().find((field) => field.id === id) || null; } querySelectorAll(selector) { return selector === '[name="jform[params][required]"]' ? this.fields.required : []; } }
class Editor { constructor() { this.value = '<p>Step</p>'; } supportsChangeObservation() { return true; } getValue() { return this.value; } setValue(value) { this.value = value; } subscribeChange() { return () => {}; } }
class Runtime { constructor(options) { this.options = options; this.destroyCalls = 0; } start() { this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; } destroy() { if (!this.destroyCalls++) { this.unsubscribe?.(); this.options.adapter.destroy(); } } }

const configuration = { enabled: true, context: 'com_guidedtours.step', targetId: '42', payloadSchemaVersion: 1, formId: 'guidedtour-dates-form', fieldIds };

test('Guided Tour Step configuration and canonical policy are exact', () => {
  assert.equal(validateStepConfiguration(configuration).targetId, '42');
  assert.throws(() => validateStepConfiguration({ ...configuration, targetId: '0' }), /target is invalid/);
  assert.throws(() => validateStepConfiguration({ ...configuration, fieldIds: { ...fieldIds, tour_id: 'jform_tour_id' } }), /configuration is invalid/);
  assert.deepEqual(STEP_CANONICAL_TASK_POLICY['step.save2copy'], { intent: 'save-copy', transport: 'native', canonical: true });
});

test('Guided Tour Step activates idempotently and destroys a stale form pair', async () => {
  const form = new Form();
  const fields = Object.fromEntries(Object.entries(fieldIds).map(([key, id]) => [key, new Element(id, key === 'position' ? 'center' : key === 'type' ? '2' : key === 'interactive_type' || key === 'required' ? '1' : '')]));
  fields.required = [new Element('jform_params_required0', '0'), new Element('jform_params_required1', '1')]; fields.required[1].checked = true;
  Object.values(fields).flat().forEach((field) => form.children.add(field)); const documentSource = new DocumentSource(form, fields); const editor = new Editor(); const runtimes = [];
  const controller = new StepAutosaveController({ documentSource, optionsReader: (key, fallback) => (key === STEP_OPTIONS_KEY ? configuration : key === 'com_autosave.runtime' ? { endpoints: { initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' } } : key === 'csrf.token' ? 'token' : fallback), editorRegistry: { get: () => editor, subscribeLifecycle: () => () => {} }, apiClientFactory: () => ({}), runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; }, coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }) });
  controller.start(); controller.start(); await controller.reconcile(); assert.equal(runtimes.length, 1);
  documentSource.dispatchEvent(new Event('joomla:updated')); await controller.reconcile(); assert.equal(runtimes.length, 1);
  form.isConnected = false; await controller.reconcile(); assert.equal(runtimes[0].destroyCalls, 1);
});

test('Guided Tour Step template renders both shared layouts once', async () => {
  const template = await readFile(new URL('../../../../administrator/components/com_guidedtours/tmpl/step/edit.php', import.meta.url), 'utf8');
  assert.equal(template.match(/joomla\.autosave\.status/g)?.length, 1);
  assert.equal(template.match(/joomla\.autosave\.recovery/g)?.length, 1);
});
