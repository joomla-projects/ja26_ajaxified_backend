/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import ArticleAutosaveController, {
  ARTICLE_CANONICAL_TASK_POLICY,
  ARTICLE_OPTIONS_KEY,
  RUNTIME_OPTIONS_KEY,
} from '../../../../media_source/com_content/src/article-autosave-controller.es6.js';

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
    this.options = [];
  }
}

class FakePresenter {
  constructor(options) {
    this.options = options;
    this.destroyCalls = 0;
  }

  destroy() {
    this.destroyCalls += 1;
  }
}

class FakeCoordinator {
  constructor(options) {
    this.options = options;
    this.startCalls = 0;
    this.destroyCalls = 0;
  }

  start() {
    this.startCalls += 1;

    return this;
  }

  destroy() {
    this.destroyCalls += 1;
  }
}

class FakeForm extends FakeElement {
  constructor(id) {
    super(id);
    this.elements = new Set();
    this.mounts = new Map();
  }

  contains(element) {
    return this.elements.has(element) && element.isConnected;
  }

  querySelectorAll(selector) {
    const mount = this.mounts.get(selector);

    if (!mount?.isConnected) {
      return [];
    }

    return [mount];
  }
}

class FakeDocument extends EventTarget {
  constructor() {
    super();
    this.elements = new Map();
    this.listenerCounts = new Map();
  }

  addEventListener(type, callback, options) {
    super.addEventListener(type, callback, options);
    this.listenerCounts.set(type, (this.listenerCounts.get(type) || 0) + 1);
  }

  removeEventListener(type, callback, options) {
    super.removeEventListener(type, callback, options);
    this.listenerCounts.set(type, Math.max(0, (this.listenerCounts.get(type) || 0) - 1));
  }

  getElementById(id) {
    return this.elements.get(id) || null;
  }
}

class FakeEditor {
  constructor(value = '<p>Article</p>', supported = true) {
    this.value = value;
    this.supported = supported;
    this.callbacks = new Set();
    this.unsubscribeCalls = 0;
  }

  supportsChangeObservation() {
    return this.supported;
  }

  subscribeChange(callback) {
    this.callbacks.add(callback);
    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.unsubscribeCalls += 1;
      this.callbacks.delete(callback);
    };
  }

  getValue() {
    return this.value;
  }

  setValue(value) {
    this.value = value;
    [...this.callbacks].forEach((callback) => callback());

    return this;
  }
}

class FakeEditorRegistry {
  constructor() {
    this.instances = new Map();
    this.lifecycleCallbacks = new Set();
    this.subscriptionCount = 0;
    this.unsubscribeCount = 0;
  }

  get(id) {
    return this.instances.get(id) || false;
  }

  subscribeLifecycle(callback) {
    this.lifecycleCallbacks.add(callback);
    this.subscriptionCount += 1;
    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.unsubscribeCount += 1;
      this.lifecycleCallbacks.delete(callback);
    };
  }

  emit(detail) {
    [...this.lifecycleCallbacks].forEach((callback) => callback(detail));
  }
}

class FakeRuntime {
  constructor(options, startResult = null) {
    this.options = options;
    this.startResult = startResult;
    this.startCalls = 0;
    this.destroyCalls = 0;
    this.changeCalls = 0;
    this.recoveryCalls = 0;
    this.unsubscribe = null;
  }

  start() {
    this.startCalls += 1;
    this.unsubscribe = this.options.adapter.subscribe(() => {
      this.changeCalls += 1;
    });

    return this.startResult || this;
  }

  destroy() {
    if (this.destroyCalls > 0) {
      return;
    }

    this.destroyCalls += 1;
    this.unsubscribe?.();
    this.options.adapter.destroy();
  }

  restoreDetectedDraft() {
    this.recoveryCalls += 1;
  }

  discardDetectedDraft() {
    this.recoveryCalls += 1;
  }

  keepCurrent() {
    this.recoveryCalls += 1;
  }
}

const endpoints = Object.freeze({
  initialize: 'index.php?task=autosave.initialize',
  preserve: 'index.php?task=autosave.preserve',
  detect: 'index.php?task=autosave.detect',
  read: 'index.php?task=autosave.read',
  discard: 'index.php?task=autosave.discard',
  prepareCanonicalAction: 'index.php?task=autosave.prepareCanonicalAction',
  getCanonicalActionOutcome: 'index.php?task=autosave.getCanonicalActionOutcome',
});

const createFixture = ({ startResults = [], presenterFactory } = {}) => {
  const document = new FakeDocument();
  const registry = new FakeEditorRegistry();
  const options = {
    [ARTICLE_OPTIONS_KEY]: {
      enabled: true,
      context: 'com_content.article',
      targetId: '42',
      payloadSchemaVersion: 1,
      formId: 'item-form',
      fieldIds: {
        title: 'jform_title',
        alias: 'jform_alias',
        articletext: 'jform_articletext',
        catid: 'jform_catid',
      },
      locale: 'en-GB',
      timeZone: 'UTC',
    },
    [RUNTIME_OPTIONS_KEY]: { endpoints: { ...endpoints } },
    'csrf.token': 'csrf-token',
  };
  const form = new FakeForm('item-form');
  const fields = {
    title: new FakeElement('jform_title', 'Title'),
    alias: new FakeElement('jform_alias', ''),
    articletext: new FakeElement('jform_articletext', 'stale'),
    catid: new FakeElement('jform_catid', '2'),
  };
  fields.catid.options = [{ value: '2' }, { value: '3' }];
  Object.values(fields).forEach((field) => form.elements.add(field));
  const statusMount = new FakeElement('item-form-autosave-status');
  const recoveryMount = new FakeElement('item-form-autosave-recovery');
  form.mounts.set('[data-joomla-autosave-status-ui]', statusMount);
  form.mounts.set('[data-joomla-autosave-recovery-ui]', recoveryMount);
  form.elements.add(statusMount);
  form.elements.add(recoveryMount);
  document.elements.set(form.id, form);
  Object.values(fields).forEach((field) => document.elements.set(field.id, field));
  const editor = new FakeEditor();
  registry.instances.set(fields.articletext.id, editor);
  const runtimes = [];
  const clients = [];
  const eventTargets = [];
  const presenters = [];
  const coordinators = [];
  const controller = new ArticleAutosaveController({
    documentSource: document,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    editorRegistry: registry,
    apiClientFactory: (configuration) => {
      const client = { configuration };
      clients.push(client);

      return client;
    },
    runtimeFactory: (configuration) => {
      const runtime = new FakeRuntime(configuration, startResults[runtimes.length]);
      runtimes.push(runtime);

      return runtime;
    },
    eventTargetFactory: () => {
      const eventTarget = new EventTarget();
      eventTargets.push(eventTarget);

      return eventTarget;
    },
    presenterFactory: presenterFactory || ((configuration) => {
      const presenter = new FakePresenter(configuration);
      presenters.push(presenter);

      return presenter;
    }),
    coordinatorFactory: (configuration) => {
      const coordinator = new FakeCoordinator(configuration);
      coordinators.push(coordinator);

      return coordinator;
    },
  });

  return {
    clients,
    controller,
    coordinators,
    document,
    editor,
    eventTargets,
    fields,
    form,
    options,
    presenters,
    registry,
    runtimes,
    recoveryMount,
    statusMount,
    removePresentationMount(type) {
      const selector = `[data-joomla-autosave-${type}-ui]`;
      const mount = form.mounts.get(selector);
      form.mounts.delete(selector);

      if (mount) {
        mount.isConnected = false;
        form.elements.delete(mount);
      }
    },
    replacePresentationMount(type) {
      const selector = `[data-joomla-autosave-${type}-ui]`;
      const current = form.mounts.get(selector);
      const replacement = new FakeElement(`item-form-autosave-${type}-replacement`);

      if (current) {
        current.isConnected = false;
        form.elements.delete(current);
      }

      form.mounts.set(selector, replacement);
      form.elements.add(replacement);

      return replacement;
    },
    replaceForm() {
      const replacement = new FakeForm('item-form');
      const replacementFields = {
        title: new FakeElement('jform_title', 'Replacement'),
        alias: new FakeElement('jform_alias', ''),
        articletext: new FakeElement('jform_articletext', 'stale replacement'),
        catid: new FakeElement('jform_catid', '2'),
      };
      replacementFields.catid.options = [{ value: '2' }, { value: '3' }];
      Object.values(replacementFields).forEach((field) => replacement.elements.add(field));
      const replacementStatusMount = new FakeElement('item-form-autosave-status-replacement');
      const replacementRecoveryMount = new FakeElement('item-form-autosave-recovery-replacement');
      replacement.mounts.set('[data-joomla-autosave-status-ui]', replacementStatusMount);
      replacement.mounts.set('[data-joomla-autosave-recovery-ui]', replacementRecoveryMount);
      replacement.elements.add(replacementStatusMount);
      replacement.elements.add(replacementRecoveryMount);
      form.isConnected = false;
      statusMount.isConnected = false;
      recoveryMount.isConnected = false;
      Object.values(fields).forEach((field) => {
        field.isConnected = false;
      });
      document.elements.set(replacement.id, replacement);
      Object.values(replacementFields).forEach((field) => document.elements.set(field.id, field));

      return {
        form: replacement,
        fields: replacementFields,
        recoveryMount: replacementRecoveryMount,
        statusMount: replacementStatusMount,
      };
    },
  };
};

test('supported existing Article activates exactly once with literal runtime configuration', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 1);
  assert.equal(fixture.registry.subscriptionCount, 1);
  assert.deepEqual(fixture.clients[0].configuration, {
    endpoints,
    csrf: 'csrf-token',
  });
  assert.equal(fixture.runtimes[0].options.context, 'com_content.article');
  assert.equal(fixture.runtimes[0].options.targetId, '42');
  assert.equal(fixture.runtimes[0].options.schemaVersion, 1);
  assert.strictEqual(fixture.runtimes[0].options.eventTarget, fixture.eventTargets[0]);
  assert.equal(Object.hasOwn(fixture.runtimes[0].options, 'onlineSource'), false);
  assert.strictEqual(fixture.presenters[0].options.eventTarget, fixture.eventTargets[0]);
  assert.strictEqual(fixture.presenters[0].options.runtime, fixture.runtimes[0]);
  assert.strictEqual(fixture.presenters[0].options.statusMount, fixture.statusMount);
  assert.strictEqual(fixture.presenters[0].options.recoveryMount, fixture.recoveryMount);
  assert.strictEqual(fixture.coordinators[0].options.taskPolicy, ARTICLE_CANONICAL_TASK_POLICY);
  assert.deepEqual(ARTICLE_CANONICAL_TASK_POLICY['article.save2copy'], {
    intent: 'save-copy', transport: 'native', canonical: true,
  });
  assert.deepEqual(Object.keys(ARTICLE_CANONICAL_TASK_POLICY), [
    'article.apply',
    'article.save',
    'article.save2new',
    'article.save2copy',
    'article.cancel',
  ]);
  assert.equal(fixture.coordinators[0].startCalls, 1);
  assert.equal(fixture.runtimes[0].changeCalls, 0);
});

test('invalid, disabled and new Article configurations remain inactive', async (t) => {
  const cases = [
    ['disabled', (fixture) => { fixture.options[ARTICLE_OPTIONS_KEY].enabled = false; }],
    ['new Article', (fixture) => { fixture.options[ARTICLE_OPTIONS_KEY].targetId = '0'; }],
    ['negative target', (fixture) => { fixture.options[ARTICLE_OPTIONS_KEY].targetId = '-1'; }],
    ['noncanonical target', (fixture) => { fixture.options[ARTICLE_OPTIONS_KEY].targetId = '02'; }],
    ['missing CSRF', (fixture) => { fixture.options['csrf.token'] = ''; }],
    ['missing endpoint', (fixture) => { delete fixture.options[RUNTIME_OPTIONS_KEY].endpoints.read; }],
  ];

  for (const [name, mutate] of cases) {
    await t.test(name, async () => {
      const fixture = createFixture();
      mutate(fixture);
      fixture.controller.start();
      await fixture.controller.reconcile();
      assert.equal(fixture.runtimes.length, 0);
      assert.equal(fixture.coordinators.length, 0);
    });
  }
});

test('missing, disconnected or incomplete forms remain inactive', async (t) => {
  const cases = [
    ['missing form', (fixture) => fixture.document.elements.delete('item-form')],
    ['disconnected form', (fixture) => { fixture.form.isConnected = false; }],
    ['missing title', (fixture) => fixture.document.elements.delete('jform_title')],
    ['missing alias', (fixture) => fixture.document.elements.delete('jform_alias')],
    ['missing category', (fixture) => fixture.document.elements.delete('jform_catid')],
    ['missing editor field', (fixture) => fixture.document.elements.delete('jform_articletext')],
  ];

  for (const [name, mutate] of cases) {
    await t.test(name, async () => {
      const fixture = createFixture();
      mutate(fixture);
      fixture.controller.start();
      await fixture.controller.reconcile();
      assert.equal(fixture.runtimes.length, 0);
    });
  }
});

test('an absent or unsupported editor waits without affecting Article editing', async () => {
  const absent = createFixture();
  absent.registry.instances.clear();
  absent.controller.start();
  await absent.controller.reconcile();
  assert.equal(absent.runtimes.length, 0);
  assert.equal(absent.presenters.length, 0);
  assert.equal(absent.coordinators.length, 0);

  const lateEditor = new FakeEditor();
  absent.registry.instances.set('jform_articletext', lateEditor);
  absent.registry.emit({
    type: 'registered', id: 'jform_articletext', editorType: 'none',
  });
  await absent.controller.reconcile();
  assert.equal(absent.runtimes.length, 1);

  const unsupported = createFixture();
  unsupported.registry.instances.set('jform_articletext', new FakeEditor('content', false));
  unsupported.controller.start();
  await unsupported.controller.reconcile();
  assert.equal(unsupported.runtimes.length, 0);
  assert.equal(unsupported.presenters.length, 0);
  assert.equal(unsupported.coordinators.length, 0);
});

test('irrelevant updates and unrelated editor lifecycle events are idempotent', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  fixture.registry.emit({ type: 'registered', id: 'another-editor', editorType: 'none' });
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].startCalls, 1);
  assert.equal(fixture.runtimes[0].destroyCalls, 0);
  assert.equal(fixture.presenters.length, 1);
  assert.equal(fixture.presenters[0].destroyCalls, 0);
  assert.equal(fixture.coordinators.length, 1);
  assert.equal(fixture.coordinators[0].startCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 0);
});

test('form replacement destroys the old pair and creates one replacement', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();
  const replacement = fixture.replaceForm();

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.coordinators.length, 2);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[1].startCalls, 1);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.equal(fixture.editor.unsubscribeCalls, 1);
  assert.strictEqual(fixture.controller.activePair.form, replacement.form);
  assert.notStrictEqual(fixture.eventTargets[0], fixture.eventTargets[1]);
  assert.strictEqual(fixture.presenters[1].options.eventTarget, fixture.eventTargets[1]);
});

test('independent UI replacements preserve the runtime, adapter and pair EventTarget', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();
  const runtime = fixture.runtimes[0];
  const adapter = fixture.controller.activePair.adapter;
  const eventTarget = fixture.eventTargets[0];
  const coordinator = fixture.coordinators[0];
  const statusReplacement = fixture.replacePresentationMount('status');

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(runtime.destroyCalls, 0);
  assert.strictEqual(fixture.controller.activePair.adapter, adapter);
  assert.strictEqual(fixture.controller.activePair.eventTarget, eventTarget);
  assert.strictEqual(fixture.controller.activePair.coordinator, coordinator);
  assert.equal(coordinator.destroyCalls, 0);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.strictEqual(fixture.presenters[1].options.runtime, runtime);
  assert.strictEqual(fixture.presenters[1].options.eventTarget, eventTarget);
  assert.strictEqual(fixture.presenters[1].options.statusMount, statusReplacement);
  assert.strictEqual(fixture.presenters[1].options.recoveryMount, fixture.recoveryMount);

  const recoveryReplacement = fixture.replacePresentationMount('recovery');
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.coordinators.length, 1);
  assert.equal(fixture.presenters[1].destroyCalls, 1);
  assert.strictEqual(fixture.presenters[2].options.statusMount, statusReplacement);
  assert.strictEqual(fixture.presenters[2].options.recoveryMount, recoveryReplacement);

  const secondStatus = fixture.replacePresentationMount('status');
  const secondRecovery = fixture.replacePresentationMount('recovery');
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.coordinators.length, 1);
  assert.equal(fixture.presenters[2].destroyCalls, 1);
  assert.strictEqual(fixture.presenters[3].options.statusMount, secondStatus);
  assert.strictEqual(fixture.presenters[3].options.recoveryMount, secondRecovery);

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.presenters.length, 4);
  assert.equal(fixture.presenters[3].destroyCalls, 0);
});

test('presentation configuration changes replace only the presenter', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();
  const runtime = fixture.runtimes[0];
  const eventTarget = fixture.eventTargets[0];

  fixture.options[ARTICLE_OPTIONS_KEY].locale = 'fr-FR';
  fixture.options[ARTICLE_OPTIONS_KEY].timeZone = 'Europe/Paris';
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.coordinators.length, 1);
  assert.equal(runtime.destroyCalls, 0);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.strictEqual(fixture.presenters[1].options.eventTarget, eventTarget);
  assert.equal(fixture.presenters[1].options.locale, 'fr-FR');
  assert.equal(fixture.presenters[1].options.timeZone, 'Europe/Paris');
});

test('partial, missing, malformed and late UI preserve headless runtime operation', async () => {
  const statusOnly = createFixture();
  statusOnly.removePresentationMount('recovery');
  statusOnly.controller.start();
  await statusOnly.controller.reconcile();
  assert.equal(statusOnly.runtimes.length, 1);
  assert.equal(statusOnly.presenters.length, 1);
  assert.strictEqual(statusOnly.presenters[0].options.statusMount, statusOnly.statusMount);
  assert.equal(statusOnly.presenters[0].options.recoveryMount, null);

  const recoveryOnly = createFixture();
  recoveryOnly.removePresentationMount('status');
  recoveryOnly.controller.start();
  await recoveryOnly.controller.reconcile();
  assert.equal(recoveryOnly.runtimes.length, 1);
  assert.equal(recoveryOnly.presenters.length, 1);
  assert.equal(recoveryOnly.presenters[0].options.statusMount, null);
  assert.strictEqual(recoveryOnly.presenters[0].options.recoveryMount, recoveryOnly.recoveryMount);

  const headless = createFixture();
  headless.removePresentationMount('status');
  headless.removePresentationMount('recovery');
  headless.controller.start();
  await headless.controller.reconcile();
  assert.equal(headless.runtimes.length, 1);
  assert.equal(headless.presenters.length, 0);
  assert.equal(headless.runtimes[0].destroyCalls, 0);

  const lateStatus = headless.replacePresentationMount('status');
  headless.document.dispatchEvent(new Event('joomla:updated'));
  await headless.controller.reconcile();
  assert.equal(headless.runtimes.length, 1);
  assert.equal(headless.presenters.length, 1);
  assert.strictEqual(headless.presenters[0].options.statusMount, lateStatus);
  assert.equal(headless.presenters[0].options.recoveryMount, null);

  const malformed = createFixture({ presenterFactory: () => null });
  malformed.controller.start();
  await malformed.controller.reconcile();
  assert.equal(malformed.runtimes.length, 1);
  assert.equal(malformed.runtimes[0].destroyCalls, 0);
});

test('editor replacement and stale lifecycle metadata cannot destroy the current pair', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();

  fixture.registry.instances.delete('jform_articletext');
  fixture.registry.emit({
    type: 'unregistered', id: 'jform_articletext', editorType: 'none',
  });
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);

  const replacement = new FakeEditor('<p>Replacement</p>');
  fixture.registry.instances.set('jform_articletext', replacement);
  fixture.registry.emit({
    type: 'registered', id: 'jform_articletext', editorType: 'tinymce',
  });
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.coordinators.length, 2);
  assert.strictEqual(fixture.controller.activePair.editor, replacement);

  fixture.registry.emit({
    type: 'unregistered', id: 'jform_articletext', editorType: 'none',
  });
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes[1].destroyCalls, 0);
  assert.strictEqual(fixture.controller.activePair.editor, replacement);
});

test('descriptor and transport changes replace the active pair exactly once', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();

  const changes = [
    () => { fixture.options[ARTICLE_OPTIONS_KEY].targetId = '43'; },
    () => { fixture.options[ARTICLE_OPTIONS_KEY].payloadSchemaVersion = 2; },
    () => { fixture.options[ARTICLE_OPTIONS_KEY].context = 'com_content.article.v2'; },
    () => { fixture.options[RUNTIME_OPTIONS_KEY].endpoints.preserve = 'changed-endpoint'; },
    () => { fixture.options['csrf.token'] = 'replacement-token'; },
  ];

  for (const change of changes) {
    const current = fixture.controller.activePair.runtime;
    change();
    await fixture.controller.reconcile();
    assert.equal(current.destroyCalls, 1);
  }

  assert.equal(fixture.runtimes.length, 6);
});

test('a stale asynchronous activation destroys only itself', async () => {
  let release;
  const pendingStart = new Promise((resolve) => {
    release = resolve;
  });
  const fixture = createFixture({ startResults: [pendingStart] });
  fixture.controller.start();
  assert.equal(fixture.runtimes.length, 1);

  fixture.options[ARTICLE_OPTIONS_KEY].targetId = '43';
  const replacement = fixture.controller.reconcile();
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.runtimes.length, 2);
  await replacement;

  release();
  await pendingStart;
  await Promise.resolve();

  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.runtimes[1].destroyCalls, 0);
  assert.strictEqual(fixture.controller.activePair.runtime, fixture.runtimes[1]);
});

test('controller teardown remains pair-owned and does not perform recovery actions', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes[0].recoveryCalls, 0);
  assert.equal(fixture.document.listenerCounts.get('submit') || 0, 0);
  assert.equal(fixture.document.listenerCounts.get('click') || 0, 0);

  fixture.controller.destroy();
  fixture.controller.destroy();
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.registry.unsubscribeCount, 1);
  assert.equal(fixture.document.listenerCounts.get('joomla:updated'), 0);
});
