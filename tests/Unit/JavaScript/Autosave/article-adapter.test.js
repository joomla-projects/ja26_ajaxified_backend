/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import ArticleAutosaveAdapter from '../../../../media_source/com_content/src/article-autosave-adapter.es6.js';

class FakeElement extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
    this.options = [];
  }
}

class FakeForm extends EventTarget {
  constructor(id) {
    super();
    this.id = id;
    this.isConnected = true;
    this.elements = new Set();
  }

  contains(element) {
    return this.elements.has(element) && element.isConnected;
  }
}

class FakeEditor {
  constructor(value) {
    this.value = value;
    this.getValueCalls = 0;
    this.setValueCalls = [];
    this.callbacks = new Set();
    this.unsubscribeCalls = 0;
    this.emitOnSet = true;
    this.throwOnValue = null;
  }

  getValue() {
    this.getValueCalls += 1;

    return this.value;
  }

  setValue(value) {
    this.setValueCalls.push(value);

    if (value === this.throwOnValue) {
      throw new Error('Editor setter failed.');
    }

    this.value = value;

    if (this.emitOnSet) {
      this.emit();
    }

    return this;
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

  emit(...args) {
    [...this.callbacks].forEach((callback) => callback(...args));
  }
}

const createFixture = () => {
  const form = new FakeForm('item-form');
  const fields = {
    title: new FakeElement('jform_title', ' Original title '),
    alias: new FakeElement('jform_alias', ''),
    articletext: new FakeElement('jform_articletext', 'stale textarea value'),
    catid: new FakeElement('jform_catid', '2'),
  };
  fields.catid.options = [{ value: '2' }, { value: '3' }];
  Object.values(fields).forEach((field) => form.elements.add(field));
  const editor = new FakeEditor('<p>Live editor value</p>');
  let currentEditor = editor;
  const adapter = new ArticleAutosaveAdapter({
    descriptor: {
      context: 'com_content.article',
      targetId: '42',
      payloadSchemaVersion: 1,
    },
    form,
    fields,
    editor,
    getCurrentEditor: () => currentEditor,
  });

  return {
    adapter,
    editor,
    fields,
    form,
    replaceEditor(value) {
      currentEditor = value;
    },
  };
};

afterEach(() => {
  delete globalThis.reportError;
});

test('constructs an exact stable descriptor without URL or task identity', () => {
  const { adapter } = createFixture();

  assert.deepEqual(adapter.getDescriptor(), {
    context: 'com_content.article',
    targetId: '42',
    payloadSchemaVersion: 1,
  });
  assert.equal(Object.isFrozen(adapter.getDescriptor()), true);
  assert.throws(() => new ArticleAutosaveAdapter({}), TypeError);
});

test('capture returns exactly four immutable-source values and uses the live editor', () => {
  const { adapter, editor } = createFixture();
  adapter.initializeBaseline();

  const payload = adapter.capture();

  assert.deepEqual(Object.keys(payload), ['title', 'alias', 'articletext', 'catid']);
  assert.deepEqual(payload, {
    title: ' Original title ',
    alias: '',
    articletext: '<p>Live editor value</p>',
    catid: 2,
  });
  assert.equal(editor.getValueCalls, 2);
});

test('capture rejects malformed categories and stale field replacements', () => {
  const invalid = ['', '0', '-1', '+2', '02', '2.0', '2e1', '4294967296'];

  invalid.forEach((value) => {
    const fixture = createFixture();
    fixture.fields.catid.value = value;
    assert.throws(() => fixture.adapter.capture(), /category/);
  });

  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  fixture.fields.title.isConnected = false;
  assert.throws(() => fixture.adapter.capture(), /no longer current/);
});

test('observes only meaningful supported changes and deduplicates equivalent events', () => {
  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  const readsAfterBaseline = fixture.editor.getValueCalls;
  let notifications = 0;
  const callbackArguments = [];
  const unsubscribe = fixture.adapter.subscribe((...args) => {
    notifications += 1;
    callbackArguments.push(args.length);
  });

  assert.equal(fixture.editor.getValueCalls, readsAfterBaseline);

  fixture.fields.title.value = 'Changed title';
  fixture.fields.title.dispatchEvent(new Event('input'));
  fixture.fields.title.dispatchEvent(new Event('change'));
  fixture.fields.alias.value = 'changed-alias';
  fixture.fields.alias.dispatchEvent(new Event('input'));
  fixture.fields.alias.dispatchEvent(new Event('change'));
  fixture.fields.catid.value = '3';
  fixture.fields.catid.dispatchEvent(new Event('change'));
  fixture.editor.value = '<p>Changed</p>';
  fixture.editor.emit('provider data must be ignored');
  fixture.editor.emit();

  const unrelated = new FakeElement('jform_metadesc', 'ignored');
  unrelated.dispatchEvent(new Event('input'));

  assert.equal(notifications, 4);
  assert.deepEqual(callbackArguments, [0, 0, 0, 0]);

  unsubscribe();
  unsubscribe();
  fixture.fields.title.value = 'After unsubscribe';
  fixture.fields.title.dispatchEvent(new Event('input'));
  fixture.editor.emit();
  assert.equal(notifications, 4);
  assert.equal(fixture.editor.unsubscribeCalls, 1);
});

test('invalid category changes remain controlled and fail only at capture', () => {
  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  let notifications = 0;
  fixture.adapter.subscribe(() => {
    notifications += 1;
  });

  fixture.fields.catid.value = '2abc';
  assert.doesNotThrow(() => fixture.fields.catid.dispatchEvent(new Event('change')));
  assert.equal(notifications, 1);
  assert.throws(() => fixture.adapter.capture(), /category/);
});

test('apply validates the whole payload before mutation and requires an available category', () => {
  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  const original = fixture.adapter.capture();
  const invalidPayloads = [
    { title: 'Missing fields' },
    { ...original, extra: true },
    { ...original, title: 3 },
    { ...original, catid: '3' },
    { ...original, catid: 4 },
  ];

  invalidPayloads.forEach((payload) => {
    assert.throws(() => fixture.adapter.apply(payload));
    assert.deepEqual(fixture.adapter.capture(), original);
  });
  assert.equal(fixture.editor.setValueCalls.length, 0);
});

test('apply writes all fields through supported APIs without producing dirty notifications', () => {
  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  let notifications = 0;
  fixture.adapter.subscribe(() => {
    notifications += 1;
  });
  const payload = {
    title: 'Recovered title',
    alias: '',
    articletext: '<section>Recovered HTML</section>',
    catid: 3,
  };

  fixture.adapter.apply(payload);
  fixture.editor.emit();
  fixture.fields.title.dispatchEvent(new Event('change'));

  assert.deepEqual(fixture.adapter.capture(), payload);
  assert.deepEqual(fixture.editor.setValueCalls, ['<section>Recovered HTML</section>']);
  assert.equal(notifications, 0);

  fixture.adapter.apply(payload);
  fixture.editor.emit();
  assert.equal(notifications, 0);
});

test('apply attempts rollback and preserves the original failure', () => {
  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  const original = fixture.adapter.capture();
  fixture.editor.throwOnValue = '<p>Fail</p>';

  assert.throws(() => fixture.adapter.apply({
    title: 'Partial title',
    alias: 'partial-alias',
    articletext: '<p>Fail</p>',
    catid: 3,
  }), /Editor setter failed/);
  assert.deepEqual(fixture.adapter.capture(), original);
});

test('apply rejects disconnected forms, stale editors and destroyed adapters', () => {
  const disconnected = createFixture();
  disconnected.adapter.initializeBaseline();
  disconnected.form.isConnected = false;
  assert.throws(() => disconnected.adapter.apply({
    title: 'A', alias: '', articletext: 'B', catid: 2,
  }), /no longer current/);

  const stale = createFixture();
  stale.adapter.initializeBaseline();
  stale.replaceEditor(new FakeEditor('replacement'));
  assert.throws(() => stale.adapter.capture(), /no longer current/);

  const destroyed = createFixture();
  destroyed.adapter.initializeBaseline();
  destroyed.adapter.destroy();
  destroyed.adapter.destroy();
  assert.throws(() => destroyed.adapter.apply({
    title: 'A', alias: '', articletext: 'B', catid: 2,
  }), /destroyed/);
});

test('destroy removes every listener and callback errors do not prevent cleanup', () => {
  const fixture = createFixture();
  fixture.adapter.initializeBaseline();
  const errors = [];
  globalThis.reportError = (error) => errors.push(error.message);
  fixture.adapter.subscribe(() => {
    throw new Error('Consumer failed.');
  });

  fixture.fields.title.value = 'Changed';
  fixture.fields.title.dispatchEvent(new Event('input'));
  assert.deepEqual(errors, ['Consumer failed.']);

  fixture.adapter.destroy();
  fixture.adapter.destroy();
  fixture.fields.title.value = 'After destroy';
  fixture.fields.title.dispatchEvent(new Event('input'));
  fixture.editor.emit();
  assert.deepEqual(errors, ['Consumer failed.']);
  assert.equal(fixture.editor.unsubscribeCalls, 1);
});
