/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import ArticleAutosaveCreateBinding, {
  HISTORY_KEY,
  STORAGE_PREFIX,
} from '../../../../media_source/com_content/src/article-autosave-create-binding.es6.js';

const context = 'com_content.article';
const provisional = `p1:${'a'.repeat(64)}`;

const createHistory = (state = null) => ({
  state,
  replaceState(next) {
    this.state = next;
  },
});

const createStorage = () => {
  const values = new Map();

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
    values,
  };
};

test('independently opened Article forms receive independent lineages', () => {
  const first = new ArticleAutosaveCreateBinding({
    context,
    historySource: createHistory(),
    storage: createStorage(),
    identityFactory: () => 'form-one',
  });
  const second = new ArticleAutosaveCreateBinding({
    context,
    historySource: createHistory(),
    storage: createStorage(),
    identityFactory: () => 'form-two',
  });

  assert.notEqual(first.formInstanceId, second.formInstanceId);
  assert.notEqual(first.initializationKey, second.initializationKey);
});

test('same history entry restores its validated provisional binding', () => {
  const history = createHistory();
  const storage = createStorage();
  const first = new ArticleAutosaveCreateBinding({
    context,
    historySource: history,
    storage,
    identityFactory: () => 'stable-form',
  });
  first.bind({ context, target_id: provisional });

  const reloaded = new ArticleAutosaveCreateBinding({
    context,
    historySource: history,
    storage,
    identityFactory: () => 'must-not-be-used',
  });

  assert.equal(reloaded.formInstanceId, 'stable-form');
  assert.equal(reloaded.targetId, provisional);
  assert.equal(reloaded.initializationKey, 'stable-form');
});

test('immutable create descriptors keep independent browser lineages in one history entry', () => {
  let next = 0;
  const history = createHistory();
  const storage = createStorage();
  const list = new ArticleAutosaveCreateBinding({
    context,
    lineageKey: 'descriptor:list',
    historySource: history,
    storage,
    identityFactory: () => `form-${++next}`,
  });
  list.bind({ context, target_id: provisional });
  const radio = new ArticleAutosaveCreateBinding({
    context,
    lineageKey: 'descriptor:radio',
    historySource: history,
    storage,
    identityFactory: () => `form-${++next}`,
  });
  const restoredList = new ArticleAutosaveCreateBinding({
    context,
    lineageKey: 'descriptor:list',
    historySource: history,
    storage,
    identityFactory: () => 'must-not-be-used',
  });

  assert.equal(list.formInstanceId, 'form-1');
  assert.equal(radio.formInstanceId, 'form-2');
  assert.equal(radio.targetId, null);
  assert.equal(restoredList.formInstanceId, 'form-1');
  assert.equal(restoredList.targetId, provisional);
});

test('malformed stored authority is discarded and never rebound', () => {
  const history = createHistory({ [HISTORY_KEY]: { [context]: 'stable-form' } });
  const storage = createStorage();
  storage.setItem(`${STORAGE_PREFIX}${context}:stable-form`, JSON.stringify({
    version: 1,
    context,
    formInstanceId: 'stable-form',
    initializationKey: 'stable-form',
    targetId: '42',
  }));

  const binding = new ArticleAutosaveCreateBinding({ context, historySource: history, storage });

  assert.equal(binding.targetId, null);
  assert.equal(storage.values.size, 0);
});

test('unavailable session storage never blocks native or in-memory create mode', () => {
  const binding = new ArticleAutosaveCreateBinding({
    context,
    historySource: createHistory(),
    storage: {
      getItem: () => { throw new Error('blocked'); },
      setItem: () => { throw new Error('blocked'); },
      removeItem: () => { throw new Error('blocked'); },
    },
    identityFactory: () => 'storage-blocked-form',
  });

  assert.doesNotThrow(() => binding.bind({ context, target_id: provisional }));
  assert.equal(binding.targetId, provisional);
});

test('Web Locks prevent a competing duplicate tab from silently owning P1', async () => {
  const lockManager = {
    request: async (name, options, callback) => callback(null),
  };
  const binding = new ArticleAutosaveCreateBinding({
    context,
    historySource: createHistory(),
    storage: createStorage(),
    lockManager,
    channelFactory: null,
    identityFactory: () => 'duplicate-form',
  });
  binding.bind({ context, target_id: provisional });

  assert.equal(await binding.acquire(provisional), false);
});

test('missing browser coordination APIs fall back to server revision protection', async () => {
  const messages = [];
  const binding = new ArticleAutosaveCreateBinding({
    context,
    historySource: createHistory(),
    storage: createStorage(),
    lockManager: null,
    channelFactory: () => ({ postMessage: (message) => messages.push(message), close() {} }),
    identityFactory: () => 'fallback-form',
  });
  binding.bind({ context, target_id: provisional });

  assert.equal(await binding.acquire(provisional), true);
  assert.deepEqual({ ...messages[0], ownerInstanceId: '<bounded>' }, {
    version: 1,
    type: 'owner',
    context,
    formInstanceId: 'fallback-form',
    ownerInstanceId: '<bounded>',
  });
  assert.match(messages[0].ownerInstanceId, /^[A-Za-z0-9._:-]{1,191}$/);
  assert.equal(JSON.stringify(messages).includes(provisional), false);
});

test('BroadcastChannel reports a payload-free duplicate-lineage ownership conflict', async () => {
  let channel;
  let conflicts = 0;
  const binding = new ArticleAutosaveCreateBinding({
    context,
    historySource: createHistory(),
    storage: createStorage(),
    lockManager: null,
    channelFactory: () => {
      channel = { postMessage() {}, close() {}, onmessage: null };

      return channel;
    },
    identityFactory: () => 'shared-form',
  });
  binding.descriptor().subscribeConflict(() => { conflicts += 1; });
  binding.bind({ context, target_id: provisional });
  await binding.acquire(provisional);
  channel.onmessage({ data: {
    version: 1,
    type: 'owner',
    context,
    formInstanceId: 'shared-form',
    ownerInstanceId: 'another-browser-context',
  } });

  assert.equal(conflicts, 1);
});

test('Save & New clears P1 and rotates to a fresh browser lineage', () => {
  let next = 0;
  const history = createHistory();
  const storage = createStorage();
  const binding = new ArticleAutosaveCreateBinding({
    context,
    historySource: history,
    storage,
    identityFactory: () => `form-${++next}`,
  });
  binding.bind({ context, target_id: provisional });
  binding.canonicalSuccess({ intent: 'save-new' });

  assert.equal(binding.formInstanceId, 'form-2');
  assert.equal(binding.initializationKey, 'form-2');
  assert.equal(binding.targetId, null);
  assert.equal(storage.values.size, 0);
});
