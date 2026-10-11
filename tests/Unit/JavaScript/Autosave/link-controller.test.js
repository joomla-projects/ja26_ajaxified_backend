/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import LinkAutosaveController, {
  LINK_CANONICAL_TASK_POLICY,
  LINK_OPTIONS_KEY,
  RUNTIME_OPTIONS_KEY,
} from '../../../../media_source/com_redirect/src/link-autosave-controller.es6.js';

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
  }
}

class FakeForm extends FakeElement {
  constructor(id) {
    super(id);
    this.children = new Set();
    this.mounts = new Map();
  }

  contains(element) {
    return this.children.has(element) && element.isConnected;
  }

  querySelectorAll(selector) {
    const mount = this.mounts.get(selector);

    return mount?.isConnected ? [mount] : [];
  }
}

class FakeDocument extends EventTarget {
  constructor() {
    super();
    this.elements = new Map();
  }

  getElementById(id) {
    return this.elements.get(id) || null;
  }
}

class FakeRuntime {
  constructor(options) {
    this.options = options;
    this.startCalls = 0;
    this.destroyCalls = 0;
    this.unsubscribe = null;
  }

  start() {
    this.startCalls += 1;
    this.unsubscribe = this.options.adapter.subscribe(() => {});

    return this;
  }

  destroy() {
    if (this.destroyCalls > 0) {
      return;
    }

    this.destroyCalls += 1;
    this.unsubscribe?.();
    this.options.adapter.destroy();
  }
}

class FakeOwnedObject {
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

const endpoints = {
  initializeCreate: 'index.php?task=autosave.initializeCreate',
  initialize: 'index.php?task=autosave.initialize',
  preserve: 'index.php?task=autosave.preserve',
  detect: 'index.php?task=autosave.detect',
  read: 'index.php?task=autosave.read',
  discard: 'index.php?task=autosave.discard',
  prepareCanonicalAction: 'index.php?task=autosave.prepareCanonicalAction',
  getCanonicalActionOutcome: 'index.php?task=autosave.getCanonicalActionOutcome',
};

const createFixture = ({ withMounts = true, createMode = false } = {}) => {
  const document = new FakeDocument();
  const options = {
    [LINK_OPTIONS_KEY]: {
      enabled: true,
      context: 'com_redirect.link',
      mode: createMode ? 'create' : 'existing',
      targetId: createMode ? null : '42',
      payloadSchemaVersion: 1,
      formId: 'link-form',
      fieldIds: {
        old_url: 'jform_old_url',
        new_url: 'jform_new_url',
        comment: 'jform_comment',
      },
      locale: 'en-GB',
      timeZone: 'UTC',
    },
    [RUNTIME_OPTIONS_KEY]: { endpoints: { ...endpoints } },
    'csrf.token': 'csrf-token',
  };
  const form = new FakeForm('link-form');
  const fields = {
    old_url: new FakeElement('jform_old_url', '/old'),
    new_url: new FakeElement('jform_new_url', '/new'),
    comment: new FakeElement('jform_comment', 'comment'),
  };
  Object.values(fields).forEach((field) => form.children.add(field));
  document.elements.set(form.id, form);
  Object.values(fields).forEach((field) => document.elements.set(field.id, field));

  if (withMounts) {
    for (const type of ['status', 'recovery']) {
      const mount = new FakeElement(`${type}-mount`);
      const selector = `[data-joomla-autosave-${type}-ui]`;
      form.mounts.set(selector, mount);
      form.children.add(mount);
    }
  }

  const clients = [];
  const runtimes = [];
  const coordinators = [];
  const presenters = [];
  const eventTargets = [];
  const controller = new LinkAutosaveController({
    documentSource: document,
    optionsReader: (key, fallback) => options[key] ?? fallback,
    createBindingFactory: ({ context }) => ({
      descriptor: () => ({
        initializationKey: 'redirect-form-instance',
        targetId: null,
        formInstanceId: 'redirect-form-instance',
        onInitialized: () => {},
        acquire: async () => true,
        release: () => {},
        onCanonicalSuccess: () => {},
        subscribeConflict: () => () => {},
        context,
      }),
    }),
    apiClientFactory: (configuration) => {
      const client = { configuration };
      clients.push(client);

      return client;
    },
    runtimeFactory: (configuration) => {
      const runtime = new FakeRuntime(configuration);
      runtimes.push(runtime);

      return runtime;
    },
    eventTargetFactory: () => {
      const target = new EventTarget();
      eventTargets.push(target);

      return target;
    },
    coordinatorFactory: (configuration) => {
      const coordinator = new FakeOwnedObject(configuration);
      coordinators.push(coordinator);

      return coordinator;
    },
    presenterFactory: (configuration) => {
      const presenter = new FakeOwnedObject(configuration);
      presenters.push(presenter);

      return presenter;
    },
  });

  return {
    clients,
    controller,
    coordinators,
    document,
    eventTargets,
    fields,
    form,
    options,
    presenters,
    runtimes,
  };
};

test('valid existing Redirect activates once through the generic integration controller', async () => {
  const fixture = createFixture();

  fixture.controller.start();
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.coordinators.length, 1);
  assert.equal(fixture.presenters.length, 1);
  assert.deepEqual(fixture.clients[0].configuration, {
    endpoints,
    csrf: 'csrf-token',
  });
  assert.deepEqual(fixture.runtimes[0].options.adapter.capture(), {
    old_url: '/old',
    new_url: '/new',
    comment: 'comment',
  });
  assert.strictEqual(fixture.runtimes[0].options.eventTarget, fixture.eventTargets[0]);
  assert.strictEqual(fixture.presenters[0].options.eventTarget, fixture.eventTargets[0]);
  assert.deepEqual(fixture.coordinators[0].options.taskPolicy, LINK_CANONICAL_TASK_POLICY);
  assert.equal('link.save2copy' in LINK_CANONICAL_TASK_POLICY, false);
});

test('new Redirect activates one unresolved runtime through the shared create binding', async () => {
  const fixture = createFixture({ createMode: true });

  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.runtimes[0].options.targetId, null);
  assert.equal(fixture.runtimes[0].options.createMode.initializationKey, 'redirect-form-instance');
});

test('new, disabled, malformed and incomplete Redirect pages remain inactive', async (t) => {
  const cases = [
    ['new link', (fixture) => { fixture.options[LINK_OPTIONS_KEY].targetId = '0'; }],
    ['disabled', (fixture) => { fixture.options[LINK_OPTIONS_KEY].enabled = false; }],
    ['missing CSRF', (fixture) => { fixture.options['csrf.token'] = ''; }],
    ['missing field', (fixture) => { fixture.document.elements.delete('jform_comment'); }],
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

test('irrelevant updates remain idempotent and form replacement creates one new pair', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();

  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await fixture.controller.reconcile();
  assert.equal(fixture.runtimes.length, 1);

  const replacement = new FakeForm('link-form');
  Object.values(fixture.fields).forEach((field) => replacement.children.add(field));
  fixture.document.elements.set('link-form', replacement);
  fixture.form.isConnected = false;
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 2);
  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.strictEqual(fixture.controller.activePair.form, replacement);
});

test('Redirect remains active headlessly when presentation mounts are absent', async () => {
  const fixture = createFixture({ withMounts: false });
  fixture.controller.start();
  await fixture.controller.reconcile();

  assert.equal(fixture.runtimes.length, 1);
  assert.equal(fixture.presenters.length, 0);
});

test('controller destruction removes owned pairs and stale fields cannot signal work', async () => {
  const fixture = createFixture();
  fixture.controller.start();
  await fixture.controller.reconcile();
  fixture.controller.destroy();
  fixture.controller.destroy();

  fixture.fields.old_url.value = '/stale';
  fixture.fields.old_url.dispatchEvent(new Event('input'));
  fixture.document.dispatchEvent(new Event('joomla:updated'));
  await Promise.resolve();

  assert.equal(fixture.runtimes[0].destroyCalls, 1);
  assert.equal(fixture.coordinators[0].destroyCalls, 1);
  assert.equal(fixture.presenters[0].destroyCalls, 1);
  assert.equal(fixture.runtimes.length, 1);
});

test('Redirect template places form-level status and scope before protected fields', async () => {
  const template = await readFile(new URL(
    '../../../../administrator/components/com_redirect/tmpl/link/edit.php',
    import.meta.url,
  ), 'utf8');
  const tab = template.indexOf("uitab.addTab', 'myTab', 'basic'");
  const status = template.indexOf("joomla.autosave.status");
  const scope = template.indexOf('COM_REDIRECT_AUTOSAVE_SCOPE_NOTICE');
  const oldUrl = template.indexOf("renderField('old_url')");
  const comment = template.indexOf("renderField('comment')");
  const published = template.indexOf("renderField('published')");

  assert.ok(tab < scope);
  assert.ok(scope < status);
  assert.ok(scope < oldUrl);
  assert.ok(oldUrl < comment);
  assert.ok(comment < published);
  assert.equal(template.match(/joomla\.autosave\.status/g)?.length, 1);
  assert.equal(template.match(/COM_REDIRECT_AUTOSAVE_SCOPE_NOTICE/g)?.length, 1);
  assert.match(
    template.slice(tab, oldUrl),
    /d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3/,
  );
});
