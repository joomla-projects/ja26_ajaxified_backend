import assert from 'node:assert/strict';
import test from 'node:test';
import WorkflowAdapter, { validatePayload as validateWorkflow } from '../../../../media_source/com_workflow/src/workflow-autosave-adapter.es6.js';
import WorkflowController, { validateConfiguration as validateWorkflowConfiguration } from '../../../../media_source/com_workflow/src/workflow-autosave-controller.es6.js';
import StageController, { validateConfiguration as validateStageConfiguration } from '../../../../media_source/com_workflow/src/stage-autosave-controller.es6.js';
import { validatePayload as validateLanguage } from '../../../../media_source/com_languages/src/language-autosave-adapter.es6.js';
import LanguageController, { validateConfiguration as validateLanguageConfiguration } from '../../../../media_source/com_languages/src/language-autosave-controller.es6.js';
import { validatePayload as validateGroup } from '../../../../media_source/com_users/src/group-autosave-adapter.es6.js';
import GroupController, { validateConfiguration as validateGroupConfiguration } from '../../../../media_source/com_users/src/group-autosave-controller.es6.js';
import LevelController, { validateConfiguration as validateLevelConfiguration } from '../../../../media_source/com_users/src/level-autosave-controller.es6.js';
import { validatePayload as validateNote } from '../../../../media_source/com_users/src/note-autosave-adapter.es6.js';
import NoteController, { validateConfiguration as validateNoteConfiguration } from '../../../../media_source/com_users/src/note-autosave-controller.es6.js';

const endpoints = {
  initialize: 'initialize', preserve: 'preserve', detect: 'detect', read: 'read',
  discard: 'discard', prepareCanonicalAction: 'prepare', getCanonicalActionOutcome: 'outcome',
};

class TestElement extends EventTarget {
  constructor(id) { super(); this.id = id; this.value = ''; this.isConnected = true; }
}

class TestForm extends TestElement {
  constructor(id) {
    super(id);
    this.children = new Set();
    this.mounts = {
      '[data-joomla-autosave-status-ui]': new TestElement(`${id}-status`),
      '[data-joomla-autosave-recovery-ui]': new TestElement(`${id}-recovery`),
    };
    Object.values(this.mounts).forEach((mount) => this.children.add(mount));
  }
  contains(element) { return this.children.has(element); }
  querySelectorAll(selector) { return this.mounts[selector] ? [this.mounts[selector]] : []; }
}

class TestDocument extends EventTarget {
  constructor(readyState, form, fields) { super(); this.readyState = readyState; this.replace(form, fields); }
  getElementById(id) { return this.elements.get(id) || null; }
  replace(form, fields) { this.form = form; this.fields = fields; this.elements = new Map([[form.id, form], ...Object.values(fields).map((field) => [field.id, field])]); }
}

class TestEditor {
  constructor() { this.value = ''; this.listeners = new Set(); }
  supportsChangeObservation() { return true; }
  getValue() { return this.value; }
  setValue(value) { this.value = value; }
  subscribeChange(listener) { this.listeners.add(listener); return () => this.listeners.delete(listener); }
}

class TestRuntime {
  constructor(options, calls) { this.options = options; this.calls = calls; this.destroyCalls = 0; }
  start() { this.calls.detect += 1; this.unsubscribe = this.options.adapter.subscribe(() => { this.calls.preserve += 1; }); return this; }
  destroy() { if (!this.destroyCalls++) { this.unsubscribe?.(); this.options.adapter.destroy(); } }
}

const contexts = [
  { Controller: WorkflowController, key: 'com_workflow.autosave.workflow', context: 'com_workflow.workflow', formId: 'workflow-form', fields: ['title', 'description'] },
  { Controller: StageController, key: 'com_workflow.autosave.stage', context: 'com_workflow.stage', formId: 'workflow-form', fields: ['title', 'description'] },
  { Controller: LanguageController, key: 'com_languages.autosave.language', context: 'com_languages.language', formId: 'language-form', fields: ['title', 'title_native', 'description', 'metadesc', 'sitename'] },
  { Controller: GroupController, key: 'com_users.autosave.group', context: 'com_users.group', formId: 'group-form', fields: ['title'] },
  { Controller: LevelController, key: 'com_users.autosave.level', context: 'com_users.level', formId: 'level-form', fields: ['title'] },
  { Controller: NoteController, key: 'com_users.autosave.note', context: 'com_users.note', formId: 'note-form', fields: ['subject', 'body'], editor: true },
];

const createBrowserFixture = (spec, readyState = 'complete') => {
  const form = new TestForm(spec.formId);
  const fields = Object.fromEntries(spec.fields.map((field) => [field, new TestElement(`jform_${field}`)]));
  Object.values(fields).forEach((field) => form.children.add(field));
  const documentSource = new TestDocument(readyState, form, fields);
  const configuration = {
    enabled: true, context: spec.context, targetId: '7', payloadSchemaVersion: 1,
    formId: spec.formId, fieldIds: Object.fromEntries(spec.fields.map((field) => [field, `jform_${field}`])),
  };
  const calls = { detect: 0, preserve: 0, ui: 0 };
  const runtimes = [];
  const editor = new TestEditor();
  const controller = new spec.Controller({
    documentSource,
    optionsReader: (key, fallback) => (key === spec.key ? configuration
      : key === 'com_autosave.runtime' ? { endpoints }
        : key === 'csrf.token' ? 'token' : fallback),
    ...(spec.editor ? { editorRegistry: { get: () => editor, subscribeLifecycle: () => () => {} } } : {}),
    apiClientFactory: () => ({}),
    runtimeFactory: (options) => { const runtime = new TestRuntime(options, calls); runtimes.push(runtime); return runtime; },
    coordinatorFactory: () => ({ start() { return this; }, destroy() {} }),
    presenterFactory: () => { calls.ui += 1; return { destroy() {} }; },
  });
  return { calls, configuration, controller, documentSource, fields, form, runtimes };
};

test('all six contexts enforce exact payload allowlists', () => {
  assert.deepEqual(validateWorkflow({ description: '', title: '' }), { title: '', description: '' });
  assert.deepEqual(validateLanguage({ title: '', title_native: '', description: '', metadesc: '', sitename: '' }), { title: '', title_native: '', description: '', metadesc: '', sitename: '' });
  assert.deepEqual(validateGroup({ title: '' }), { title: '' });
  assert.deepEqual(validateNote({ body: '', subject: '' }), { subject: '', body: '' });
  assert.throws(() => validateWorkflow({ title: '', description: '', extension: 'com_content' }), TypeError);
  assert.throws(() => validateLanguage({ title: '', title_native: '', description: '', metadesc: '', sitename: '', sef: 'en' }), TypeError);
  assert.throws(() => validateGroup({ title: '', rules: [] }), TypeError);
  assert.throws(() => validateNote({ subject: '', body: '', user_id: '7' }), TypeError);
});

test('component controllers accept only literal context-specific configuration', () => {
  const configuration = (context, fields, formId = 'form') => ({ enabled: true, context, targetId: '7', payloadSchemaVersion: 1, formId, fieldIds: Object.fromEntries(fields.map((field) => [field, `jform_${field}`])) });
  assert.equal(validateWorkflowConfiguration(configuration('com_workflow.workflow', ['title', 'description'])).targetId, '7');
  assert.equal(validateStageConfiguration(configuration('com_workflow.stage', ['title', 'description'])).context, 'com_workflow.stage');
  assert.equal(validateLanguageConfiguration(configuration('com_languages.language', ['title', 'title_native', 'description', 'metadesc', 'sitename'])).context, 'com_languages.language');
  assert.equal(validateGroupConfiguration(configuration('com_users.group', ['title'])).context, 'com_users.group');
  assert.equal(validateLevelConfiguration(configuration('com_users.level', ['title'])).context, 'com_users.level');
  assert.equal(validateNoteConfiguration(configuration('com_users.note', ['subject', 'body'])).context, 'com_users.note');
  assert.throws(() => validateGroupConfiguration(configuration('com_users.level', ['title'])), TypeError);
  assert.equal(validateNoteConfiguration({ enabled: false }), null);
});

test('scalar adapter captures immutable snapshots and restores only approved controls', () => {
  const listeners = new Map();
  const form = { isConnected: true, contains: () => true };
  const field = (value) => ({ value, isConnected: true, addEventListener: (type, fn) => listeners.set(type, fn), removeEventListener: (type) => listeners.delete(type), dispatchEvent: () => true });
  const fields = { title: field('One'), description: field('Two') };
  const adapter = new WorkflowAdapter({ descriptor: { context: 'com_workflow.workflow', targetId: '7', payloadSchemaVersion: 1 }, form, fields, eventFactory: () => ({}) }).initializeBaseline();
  const first = adapter.capture(); first.title = 'mutated'; assert.equal(adapter.capture().title, 'One');
  adapter.apply({ title: 'Restored', description: 'Body' });
  assert.deepEqual(adapter.capture(), { title: 'Restored', description: 'Body' });
  adapter.destroy(); assert.equal(listeners.size, 0);
});

for (const spec of contexts) {
  test(`${spec.context} activates and initiates detection in every document readiness mode`, async () => {
    for (const readyState of ['loading', 'interactive', 'complete']) {
      const fixture = createBrowserFixture(spec, readyState);
      fixture.controller.start();
      await fixture.controller.reconcile();
      assert.equal(fixture.runtimes.length, 1, `${readyState} creates one runtime`);
      assert.equal(fixture.calls.detect, 1, `${readyState} initiates detection once`);
      assert.equal(fixture.calls.ui, 1, `${readyState} activates the status/recovery presenter once`);
      assert.equal(fixture.runtimes[0].options.context, spec.context);
      fixture.fields[spec.fields[0]].value = 'changed';
      fixture.fields[spec.fields[0]].dispatchEvent(new Event('input'));
      assert.equal(fixture.calls.preserve, 1, `${readyState} observes an approved field`);
      fixture.controller.destroy();
    }
  });

  test(`${spec.context} reuses its pair, replaces stale forms, and falls back safely`, async () => {
    const fixture = createBrowserFixture(spec);
    fixture.controller.start();
    await fixture.controller.reconcile();
    fixture.documentSource.dispatchEvent(new Event('joomla:updated'));
    await fixture.controller.reconcile();
    assert.equal(fixture.runtimes.length, 1, 'the same form does not duplicate activation');

    fixture.form.isConnected = false;
    const replacement = new TestForm(spec.formId);
    const replacementFields = Object.fromEntries(spec.fields.map((field) => [field, new TestElement(`jform_${field}`)]));
    Object.values(replacementFields).forEach((field) => replacement.children.add(field));
    fixture.documentSource.replace(replacement, replacementFields);
    await fixture.controller.reconcile();
    assert.equal(fixture.runtimes.length, 2, 'a replacement form creates one current runtime');
    assert.equal(fixture.runtimes[0].destroyCalls, 1, 'the obsolete runtime is destroyed');
    fixture.controller.destroy();

    for (const invalid of [
      { enabled: false },
      { ...fixture.configuration, targetId: '0' },
    ]) {
      const fallback = createBrowserFixture(spec);
      fallback.controller.optionsReader = (key, defaultValue) => (key === spec.key ? invalid : defaultValue);
      fallback.controller.start();
      await fallback.controller.reconcile();
      assert.equal(fallback.runtimes.length, 0, 'invalid configuration remains native fallback');
      fallback.controller.destroy();
    }

    const missingField = createBrowserFixture(spec);
    missingField.fields[spec.fields[0]].isConnected = false;
    missingField.controller.start();
    await missingField.controller.reconcile();
    assert.equal(missingField.runtimes.length, 0, 'missing controls remain native fallback');
    missingField.controller.destroy();
  });
}
