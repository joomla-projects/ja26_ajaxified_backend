/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import LinkAutosaveAdapter, {
  normalizeCanonicalId,
  validatePayload,
} from '../../../../media_source/com_redirect/src/link-autosave-adapter.es6.js';

class FakeControl extends EventTarget {
  constructor(id, value = '') {
    super();
    this.id = id;
    this.value = value;
    this.isConnected = true;
  }
}

const createFixture = ({ eventFactory = (type) => new Event(type) } = {}) => {
  const fields = {
    old_url: new FakeControl('jform_old_url', ' /old '),
    new_url: new FakeControl('jform_new_url', ' /new '),
    comment: new FakeControl('jform_comment', ' Exact comment '),
  };
  const children = new Set(Object.values(fields));
  const form = {
    isConnected: true,
    contains: (element) => children.has(element),
  };
  const adapter = new LinkAutosaveAdapter({
    descriptor: {
      context: 'com_redirect.link',
      targetId: '42',
      payloadSchemaVersion: 1,
    },
    form,
    fields,
    eventFactory,
  });

  return {
    adapter,
    children,
    fields,
    form,
  };
};

test('Redirect adapter captures exactly the three literal allow-listed values', () => {
  const { adapter, fields } = createFixture();
  const first = adapter.capture();

  assert.deepEqual(first, {
    old_url: ' /old ',
    new_url: ' /new ',
    comment: ' Exact comment ',
  });
  assert.deepEqual(Object.keys(first), ['old_url', 'new_url', 'comment']);

  first.old_url = 'mutated snapshot';
  assert.equal(fields.old_url.value, ' /old ');
  assert.equal(adapter.capture().old_url, ' /old ');
});

test('Redirect adapter observes only supported controls and emits metadata-free dirty signals', () => {
  const { adapter, fields } = createFixture();
  const excluded = new FakeControl('jform_published', '1');
  const calls = [];

  adapter.initializeBaseline();
  const unsubscribe = adapter.subscribe((...args) => calls.push(args));

  fields.old_url.value = '/changed';
  fields.old_url.dispatchEvent(new Event('input'));
  fields.old_url.dispatchEvent(new Event('change'));
  fields.new_url.value = '/destination';
  fields.new_url.dispatchEvent(new Event('input'));
  fields.comment.value = 'changed';
  fields.comment.dispatchEvent(new Event('change'));
  excluded.dispatchEvent(new Event('change'));

  assert.equal(calls.length, 3);
  calls.forEach((args) => assert.deepEqual(args, []));

  unsubscribe();
  unsubscribe();
  fields.comment.value = 'after unsubscribe';
  fields.comment.dispatchEvent(new Event('input'));
  assert.equal(calls.length, 3);
});

test('Redirect recovery validates the whole payload before mutation', () => {
  const { adapter, fields } = createFixture();
  const before = adapter.capture();

  for (const payload of [
    { old_url: '/a', new_url: '/b' },
    { old_url: '/a', new_url: '/b', comment: '', published: 1 },
    { old_url: '/a', new_url: [], comment: '' },
    { old_url: 'a'.repeat(2049), new_url: '', comment: '' },
  ]) {
    assert.throws(() => adapter.apply(payload), /recovery payload is invalid/);
    assert.deepEqual(adapter.capture(), before);
  }

  assert.equal(fields.old_url.value, before.old_url);
});

test('Redirect recovery applies exact values without self-generated dirty signals', () => {
  const { adapter, fields } = createFixture();
  let dirty = 0;

  adapter.initializeBaseline();
  adapter.subscribe(() => { dirty += 1; });
  adapter.apply({
    old_url: '  /restored%20source  ',
    new_url: '',
    comment: ' restored\tcomment ',
  });

  assert.deepEqual(
    Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field.value])),
    {
      old_url: '  /restored%20source  ',
      new_url: '',
      comment: ' restored\tcomment ',
    },
  );
  assert.equal(dirty, 0);
});

test('Redirect recovery rolls back every field after a partial application failure', () => {
  let eventCount = 0;
  let failed = false;
  const fixture = createFixture({
    eventFactory: (type) => {
      eventCount += 1;

      if (!failed && eventCount === 3) {
        failed = true;
        throw new Error('synthetic synchronization failure');
      }

      return new Event(type);
    },
  });
  const before = fixture.adapter.capture();

  assert.throws(
    () => fixture.adapter.apply({ old_url: '/restored-old', new_url: '/restored-new', comment: 'restored' }),
    /synthetic synchronization failure/,
  );
  assert.deepEqual(fixture.adapter.capture(), before);
});

test('Redirect adapter rejects stale controls and destroy is idempotent', () => {
  const { adapter, children, fields, form } = createFixture();
  let dirty = 0;

  adapter.subscribe(() => { dirty += 1; });
  children.delete(fields.comment);
  assert.throws(() => adapter.capture(), /no longer current/);

  children.add(fields.comment);
  form.isConnected = false;
  assert.throws(() => adapter.apply({ old_url: '', new_url: '', comment: '' }), /no longer current/);

  form.isConnected = true;
  adapter.destroy();
  adapter.destroy();
  fields.old_url.value = '/after-destroy';
  fields.old_url.dispatchEvent(new Event('input'));
  assert.equal(dirty, 0);
  assert.throws(() => adapter.capture(), /no longer current/);
});

test('Redirect payload and target validators reject noncanonical values', () => {
  assert.equal(normalizeCanonicalId('4294967295'), '4294967295');

  for (const id of ['', '0', '-1', '01', '1.0', '4294967296']) {
    assert.throws(() => normalizeCanonicalId(id), /target is invalid/);
  }

  assert.deepEqual(
    validatePayload({ old_url: '', new_url: '', comment: '' }),
    { old_url: '', new_url: '', comment: '' },
  );
});
