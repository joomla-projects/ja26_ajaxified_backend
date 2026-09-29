import assert from 'node:assert/strict';
import test from 'node:test';
import { validatePayload as validateCategory } from '../../../../media_source/com_categories/src/category-autosave-adapter.es6.js';
import CategoryController, { validateConfiguration as validateCategoryConfiguration } from '../../../../media_source/com_categories/src/category-autosave-controller.es6.js';
import { validatePayload as validateTag } from '../../../../media_source/com_tags/src/tag-autosave-adapter.es6.js';
import TagController, { validateConfiguration as validateTagConfiguration } from '../../../../media_source/com_tags/src/tag-autosave-controller.es6.js';
import { validatePayload as validateGroup } from '../../../../media_source/com_fields/src/group-autosave-adapter.es6.js';
import GroupController, { validateConfiguration as validateGroupConfiguration } from '../../../../media_source/com_fields/src/group-autosave-controller.es6.js';

const endpoints = { initialize: 'initialize', preserve: 'preserve', detect: 'detect', read: 'read', discard: 'discard', prepareCanonicalAction: 'prepare', getCanonicalActionOutcome: 'outcome' };
class Element extends EventTarget { constructor(id) { super(); this.id = id; this.value = ''; this.isConnected = true; } }
class Form extends Element { constructor(id) { super(id); this.children = new Set(); this.mounts = { '[data-joomla-autosave-status-ui]': new Element('status'), '[data-joomla-autosave-recovery-ui]': new Element('recovery') }; Object.values(this.mounts).forEach((item) => this.children.add(item)); } contains(item) { return this.children.has(item); } querySelectorAll(selector) { return this.mounts[selector] ? [this.mounts[selector]] : []; } }
class Document extends EventTarget { constructor(form, fields) { super(); this.elements = new Map([[form.id, form], ...Object.values(fields).map((field) => [field.id, field])]); } getElementById(id) { return this.elements.get(id) || null; } }
class Editor { constructor() { this.value = ''; this.listeners = new Set(); } supportsChangeObservation() { return true; } getValue() { return this.value; } setValue(value) { this.value = value; } subscribeChange(listener) { this.listeners.add(listener); return () => this.listeners.delete(listener); } }
class Runtime { constructor(options, calls) { this.options = options; this.calls = calls; } start() { this.calls.detect += 1; this.unsubscribe = this.options.adapter.subscribe(() => { this.calls.preserve += 1; }); return this; } destroy() { this.unsubscribe?.(); this.options.adapter.destroy(); } }

const richFields = ['title', 'note', 'description', 'version_note', 'metadesc', 'metakey'];
const specs = [
  { Controller: CategoryController, key: 'com_categories.autosave.category', context: 'com_categories.category', fields: [...richFields, 'parent_id'], editor: true, schema: 2 },
  { Controller: TagController, key: 'com_tags.autosave.tag', context: 'com_tags.tag', fields: [...richFields, 'parent_id'], editor: true, schema: 2 },
  { Controller: GroupController, key: 'com_fields.autosave.group', context: 'com_fields.group', fields: ['title', 'note', 'description'], schema: 1 },
];
const fixture = (spec) => {
  const form = new Form('item-form'); const fields = Object.fromEntries(spec.fields.map((key) => [key, new Element(`jform_${key}`)])); Object.values(fields).forEach((field) => form.children.add(field));
  if (fields.parent_id) fields.parent_id.value = '1';
  const documentSource = new Document(form, fields); const editor = new Editor(); const calls = { detect: 0, preserve: 0 }; const runtimes = [];
  const configuration = { enabled: true, context: spec.context, targetId: '7', payloadSchemaVersion: spec.schema, formId: 'item-form', fieldIds: Object.fromEntries(spec.fields.map((key) => [key, `jform_${key}`])) };
  const controller = new spec.Controller({ documentSource, optionsReader: (key, fallback) => (key === spec.key ? configuration : key === 'com_autosave.runtime' ? { endpoints } : key === 'csrf.token' ? 'token' : fallback), ...(spec.editor ? { editorRegistry: { get: () => editor, subscribeLifecycle: () => () => {} } } : {}), apiClientFactory: () => ({}), runtimeFactory: (options) => { const runtime = new Runtime(options, calls); runtimes.push(runtime); return runtime; }, coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ destroy() {} }) });
  return { calls, controller, fields, runtimes };
};

test('hierarchical metadata payloads are exact and ordered', () => {
  const rich = { metakey: '', title: '', note: '', description: '', version_note: '', metadesc: '' };
  assert.deepEqual(validateCategory(rich), Object.fromEntries(richFields.map((key) => [key, ''])));
  assert.deepEqual(validateTag({ ...rich, parent_id: 1 }), { ...Object.fromEntries(richFields.map((key) => [key, ''])), parent_id: 1 });
  assert.deepEqual(validateGroup({ description: '', note: '', title: '' }), { title: '', note: '', description: '' });
  assert.throws(() => validateCategory({ ...rich, parent_id: '2' }), TypeError);
  assert.throws(() => validateGroup({ title: '', note: '', description: '', context: 'x' }), TypeError);
});
test('configuration is context-specific and rejects ID zero', () => {
  const config = (context, fields, schema, targetId = '7') => ({ enabled: true, context, targetId, payloadSchemaVersion: schema, formId: 'item-form', fieldIds: Object.fromEntries(fields.map((key) => [key, `jform_${key}`])) });
  const categoryFields = [...richFields, 'parent_id'];
  const categoryConfig = config('com_categories.category', categoryFields, 2);
  assert.equal(validateCategoryConfiguration(categoryConfig).targetId, '7');
  assert.equal(validateCategoryConfiguration(categoryConfig).payloadSchemaVersion, 2);
  assert.equal(validateTagConfiguration(config('com_tags.tag', [...richFields, 'parent_id'], 2)).context, 'com_tags.tag');
  assert.equal(validateGroupConfiguration(config('com_fields.group', ['title', 'note', 'description'], 1)).context, 'com_fields.group');
  assert.throws(() => validateCategoryConfiguration(config('com_categories.category', categoryFields, 2, '0')), TypeError);
});
for (const spec of specs) test(`${spec.context} starts one runtime and observes approved fields`, async () => { const current = fixture(spec); current.controller.start(); await current.controller.reconcile(); await current.controller.reconcile(); assert.equal(current.runtimes.length, 1); assert.equal(current.calls.detect, 1); assert.equal(current.runtimes[0].options.context, spec.context); current.fields.title.value = 'Changed'; current.fields.title.dispatchEvent(new Event('input')); assert.equal(current.calls.preserve, 1); current.controller.destroy(); });
