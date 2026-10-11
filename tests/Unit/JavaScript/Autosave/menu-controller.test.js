/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import MenuAutosaveController, { MENU_CANONICAL_TASK_POLICY, MENU_OPTIONS_KEY, validateMenuConfiguration } from '../../../../media_source/com_menus/src/menu-autosave-controller.es6.js';

class Element extends EventTarget { constructor(id, value = '') { super(); this.id = id; this.value = value; this.isConnected = true; } }
class Form extends Element { constructor() { super('item-form'); this.children = new Set(); } contains(element) { return this.children.has(element); } querySelectorAll() { return []; } }
class DocumentSource extends EventTarget { constructor(elements) { super(); this.elements = elements; } getElementById(id) { return this.elements.get(id) || null; } }
class Runtime { constructor(options) { this.options = options; this.destroyCalls = 0; } start() { this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; } destroy() { if (!this.destroyCalls++) { this.unsubscribe?.(); this.options.adapter.destroy(); } } }

const configuration = { enabled: true, context: 'com_menus.menu', targetId: '42', payloadSchemaVersion: 1, formId: 'item-form', fieldIds: { title: 'jform_title', description: 'jform_menudescription' } };

test('Menu configuration is exact and task policy excludes Save as Copy', () => {
  assert.equal(validateMenuConfiguration(configuration).targetId, '42');
  assert.throws(() => validateMenuConfiguration({ ...configuration, targetId: '0' }), /target is invalid/);
  assert.throws(() => validateMenuConfiguration({ ...configuration, fieldIds: { ...configuration.fieldIds, menutype: 'jform_menutype' } }), /configuration is invalid/);
  assert.equal('menu.save2copy' in MENU_CANONICAL_TASK_POLICY, false);
});

test('Menu activates once, handles updates and tears down replaced forms', async () => {
  const form = new Form(); const fields = { title: new Element('jform_title', 'Menu'), description: new Element('jform_menudescription', '') };
  Object.values(fields).forEach((field) => form.children.add(field)); const elements = new Map([[form.id, form], ...Object.values(fields).map((field) => [field.id, field])]);
  const documentSource = new DocumentSource(elements); const runtimes = [];
  const controller = new MenuAutosaveController({ documentSource, optionsReader: (key, fallback) => (key === MENU_OPTIONS_KEY ? configuration : key === 'com_autosave.runtime' ? { endpoints: { initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' } } : key === 'csrf.token' ? 'token' : fallback), apiClientFactory: () => ({}), runtimeFactory: (options) => { const runtime = new Runtime(options); runtimes.push(runtime); return runtime; }, coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }) });
  controller.start(); controller.start(); await controller.reconcile(); assert.equal(runtimes.length, 1);
  documentSource.dispatchEvent(new Event('joomla:updated')); await controller.reconcile(); assert.equal(runtimes.length, 1);
  form.isConnected = false; await controller.reconcile(); assert.equal(runtimes[0].destroyCalls, 1);
});

test('Menu template renders shared Autosave layouts once', async () => {
  const template = await readFile(new URL('../../../../administrator/components/com_menus/tmpl/menu/edit.php', import.meta.url), 'utf8');
  assert.equal(template.match(/joomla\.autosave\.status/g)?.length, 1);
  assert.equal(template.match(/joomla\.autosave\.recovery/g)?.length, 1);
});
