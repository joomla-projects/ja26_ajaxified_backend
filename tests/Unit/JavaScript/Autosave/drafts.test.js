/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import test from 'node:test';
import DraftRuntime, { creationRecovery } from '../../../../media_source/com_autosave/js/drafts.es6.js';

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
};
const response = (data) => ({ ok: true, json: async () => ({ success: true, data }) });
const acknowledgement = (revision, conflict = false) => response({
  client_revision: revision, updated_at: '2026-10-11T00:00:00Z', conflict,
});
const setup = (t, options = {}) => {
  const runtime = new DraftRuntime({
    binding: { draftId: 'a'.repeat(32), version: 1, endpoint: 'http://localhost/administrator/index.php?option=com_autosave&format=json' },
    capture: () => ({ title: '' }),
    fetcher: async (url, request) => acknowledgement(JSON.parse(request.body).client_revision),
    csrfToken: () => 'native-token',
    debounce: 10000,
    ...options,
  });
  t.after(() => runtime.destroy());
  return runtime;
};

test('render binding starts with no initialization or canonical prepare request', (t) => {
  let requests = 0;
  let captures = 0;
  setup(t, {
    capture: () => {
      captures += 1;
      return { title: '' };
    },
    fetcher: () => {
      requests += 1;
    },
  });
  assert.equal(requests, 0);
  assert.equal(captures, 0);
});

test('observation is cheap and capture occurs once for a coalesced draft', async (t) => {
  let captures = 0;
  const runtime = setup(t, { capture: () => {
    captures += 1;
    return { title: '' };
  } });
  for (let index = 0; index < 100; index += 1) runtime.changed();
  assert.equal(captures, 0);
  await runtime.flush();
  assert.equal(captures, 1);
  assert.equal(runtime.state, 'preserved');
  assert.equal(runtime.revision, 100);
});

test('only one preserve is outstanding and later edits coalesce after it', async (t) => {
  const first = deferred();
  const bodies = [];
  let value = 'first';
  const runtime = setup(t, {
    capture: () => ({ title: value }),
    fetcher: async (url, request) => {
      bodies.push(JSON.parse(request.body));
      return bodies.length === 1 ? first.promise : acknowledgement(bodies.at(-1).client_revision);
    },
  });
  runtime.changed();
  const pending = runtime.flush();
  value = 'later';
  runtime.changed();
  runtime.changed();
  assert.equal(runtime.flush(), pending);
  assert.equal(bodies.length, 1);
  first.resolve(acknowledgement(1));
  await pending;
  await runtime.flush();
  assert.equal(bodies.length, 2);
  assert.equal(bodies[1].client_revision, 3);
  assert.deepEqual(bodies[1].payload, { title: 'later' });
  assert.equal(runtime.state, 'preserved');
});

test('a response is required before a snapshot is described as preserved', async (t) => {
  const waiting = deferred();
  const runtime = setup(t, { fetcher: () => waiting.promise });
  runtime.changed();
  const request = runtime.flush();
  assert.equal(runtime.state, 'preserving');
  assert.equal(runtime.lastPreserved, null);
  waiting.resolve(acknowledgement(1));
  await request;
  assert.equal(runtime.lastPreserved, '2026-10-11T00:00:00Z');
});

test('timeout includes response body and does not leave preservation stuck', async (t) => {
  const runtime = setup(t, {
    timeout: 20,
    fetcher: async () => ({ ok: true, json: () => new Promise(() => {}) }),
  });
  runtime.changed();
  assert.equal(await runtime.flush(), false);
  assert.equal(runtime.state, 'offline');
  assert.equal(runtime.inFlight, null);
  assert.equal(runtime.requests.size, 0);
  assert.equal(runtime.lastPreserved, null);
});

for (const invalid of [
  null,
  { client_revision: 1 },
  { client_revision: 1, updated_at: 'invalid', conflict: false },
  { client_revision: 1, updated_at: 42, conflict: false },
  { client_revision: 1, updated_at: '2026-10-11T00:00:00Z' },
  { client_revision: 1, updated_at: '2026-10-11T00:00:00Z', conflict: 'true' },
]) {
  test('malformed preservation acknowledgement stays uncertain: ' + JSON.stringify(invalid), async (t) => {
    const runtime = setup(t, { fetcher: async () => response(invalid) });
    runtime.changed();
    assert.equal(await runtime.flush(), false);
    assert.equal(runtime.state, 'offline');
    assert.equal(runtime.lastPreserved, null);
    assert.equal(runtime.blocked, true);
    assert.equal(runtime.lastAttempt.acknowledged, false);
    assert.deepEqual(runtime.failed.payload, { title: '' });
    assert.equal(typeof runtime.submitted(), 'number', 'Native submission remains synchronous.');
  });
}

test('a malformed acknowledgement retains the previous protection time and retries its exact snapshot', async (t) => {
  const bodies = [];
  let value = 'first';
  const runtime = setup(t, {
    capture: () => ({ title: value }),
    fetcher: async (url, request) => {
      bodies.push(request.body);
      const revision = JSON.parse(request.body).client_revision;
      return bodies.length === 2 ? response({ client_revision: revision }) : acknowledgement(revision);
    },
  });
  runtime.changed();
  await runtime.flush();
  const previous = runtime.lastPreserved;
  value = 'uncertain';
  runtime.changed();
  assert.equal(await runtime.flush(), false);
  assert.equal(runtime.lastPreserved, previous);
  value = 'later';
  runtime.changed();
  assert.equal(await runtime.retry(), true);
  assert.equal(bodies[1], bodies[2]);
  await runtime.flush();
  assert.equal(JSON.parse(bodies[3]).payload.title, 'later');
});

test('transport retry keeps the exact sequence and snapshot despite newer edits', async (t) => {
  const bodies = [];
  let value = 'uncertain snapshot';
  const runtime = setup(t, {
    capture: () => ({ title: value }),
    fetcher: async (url, request) => {
      bodies.push(request.body);
      if (bodies.length === 1) throw new Error('offline');
      return acknowledgement(JSON.parse(request.body).client_revision);
    },
  });
  runtime.changed();
  await runtime.flush();
  value = 'newer edits';
  runtime.changed();
  await runtime.retry();
  assert.equal(bodies[0], bodies[1]);
  await runtime.flush();
  assert.equal(JSON.parse(bodies[2]).payload.title, 'newer edits');
  assert.equal(runtime.state, 'preserved');
});

test('native submission is synchronous even while the draft endpoint is unavailable', async (t) => {
  const waiting = deferred();
  const runtime = setup(t, { fetcher: () => waiting.promise });
  runtime.changed();
  const submitted = runtime.submitted();
  assert.equal(submitted, 2);
  runtime.changed();
  assert.equal(submitted, 2);
  assert.equal(runtime.revision, 3);
  waiting.resolve(acknowledgement(2));
  await runtime.inFlight;
});

test('retirement of an older request does not suppress a later dirty sequence', async (t) => {
  const waiting = deferred();
  let requests = 0;
  const runtime = setup(t, {
    fetcher: async (url, request) => {
      requests += 1;
      return requests === 1 ? waiting.promise : acknowledgement(JSON.parse(request.body).client_revision);
    },
  });
  runtime.changed();
  const first = runtime.flush();
  runtime.changed();
  waiting.resolve({ ok: false, json: async () => ({ success: false, error: { code: 'draft_retired' } }) });
  await first;
  await runtime.flush();
  assert.equal(requests, 2);
  assert.equal(runtime.state, 'preserved');
});

test('discard makes a delayed preserve acknowledgement unable to change the UI', async (t) => {
  const waiting = deferred();
  const runtime = setup(t, {
    fetcher: async (url) => url.includes('draft.discard') ? response({ discarded: true }) : waiting.promise,
  });
  runtime.changed();
  const preserve = runtime.flush();
  await runtime.discard('a'.repeat(32));
  waiting.resolve(acknowledgement(1));
  await preserve;
  assert.equal(runtime.state, 'discarded');
  assert.equal(runtime.lastPreserved, null);
  runtime.changed();
  assert.equal(await runtime.flush(), false);
});

test('disposed native forms ignore late responses and unsubscribe request ownership', async (t) => {
  const waiting = deferred();
  const states = [];
  const runtime = setup(t, { fetcher: () => waiting.promise, onState: (state) => states.push(state.state) });
  runtime.changed();
  const request = runtime.flush();
  runtime.destroy();
  const count = states.length;
  waiting.resolve(acknowledgement(1));
  await request;
  assert.equal(states.length, count);
  assert.equal(runtime.lastPreserved, null);
  assert.equal(runtime.requests.size, 0);
});

test('confirmed preservation with an uncertain canonical baseline discloses conflict', async (t) => {
  const runtime = setup(t, { fetcher: async () => acknowledgement(1, true) });
  runtime.changed();
  await runtime.flush();
  assert.equal(runtime.state, 'conflict');
  assert.equal(runtime.lastPreserved, '2026-10-11T00:00:00Z');
  runtime.changed();
  assert.equal(await runtime.flush(), false);
});

test('unsupported controls leave native submission metadata optional', (t) => {
  const runtime = setup(t, { capture: () => {
    throw new Error('Unsupported provider');
  } });
  assert.equal(runtime.submitted(), null);
  assert.equal(runtime.state, 'unavailable');
  assert.equal(runtime.lastPreserved, null);
});

test('native submission captures its protected values once and freezes that projection', async (t) => {
  let captures = 0;
  let sent;
  const runtime = setup(t, {
    capture: () => {
      captures += 1;
      return { title: 'submitted' };
    },
    fetcher: async (url, request) => {
      sent = JSON.parse(request.body);
      return acknowledgement(sent.client_revision);
    },
  });
  assert.equal(runtime.submitted(), 1);
  await runtime.inFlight;
  assert.equal(captures, 1);
  assert.deepEqual(sent.payload, { title: 'submitted' });
});

test('detection shows a busy state and becomes ready only after a valid acknowledgement', async (t) => {
  const pending = deferred();
  const runtime = setup(t, { fetcher: () => pending.promise });
  const detection = runtime.candidates();
  assert.equal(runtime.state, 'detecting');
  pending.resolve(response({ candidates: [], conflict: false }));
  await detection;
  assert.equal(runtime.state, 'clean');
  assert.equal(runtime.lastPreserved, null);
});

test('failed detection remains unavailable for protection and can retry without native saving', async (t) => {
  let attempts = 0;
  const runtime = setup(t, { fetcher: async () => {
    attempts += 1;
    if (attempts === 1) throw new TypeError('Network unavailable');
    return response({ candidates: [], conflict: false });
  } });
  await assert.rejects(runtime.candidates(), { code: 'transport_failure' });
  assert.equal(runtime.state, 'offline');
  await runtime.candidates();
  assert.equal(runtime.state, 'clean');
  assert.equal(runtime.revision, 0);
});

test('malformed detection data never claims readiness', async (t) => {
  const runtime = setup(t, { fetcher: async () => response({ candidates: [{ draft_id: 'unknown', updated_at: 'invalid' }], conflict: false }) });
  await assert.rejects(runtime.candidates(), { code: 'service_unavailable' });
  assert.equal(runtime.state, 'offline');
});

test('late detection failure cannot replace an acknowledged preserve status', async (t) => {
  const pending = deferred();
  const runtime = setup(t, { fetcher: async (url, request) => (new URL(url).searchParams.get('task') === 'draft.detect'
    ? pending.promise
    : acknowledgement(JSON.parse(request.body).client_revision)) });
  const detection = runtime.candidates();
  runtime.changed();
  await runtime.flush();
  pending.reject(new TypeError('Old detection failed'));
  await assert.rejects(detection, { code: 'transport_failure' });
  assert.equal(runtime.state, 'preserved');
  assert.equal(runtime.lastPreserved, '2026-10-11T00:00:00Z');
});

test('late detection conflict cannot replace a newer validated preservation', async (t) => {
  const pending = deferred();
  const runtime = setup(t, { fetcher: async (url, request) => (new URL(url).searchParams.get('task') === 'draft.detect'
    ? pending.promise
    : acknowledgement(JSON.parse(request.body).client_revision)) });
  const detection = runtime.candidates();
  runtime.changed();
  await runtime.flush();
  pending.resolve(response({ candidates: [], conflict: true }));
  await detection;
  assert.equal(runtime.state, 'preserved');
  assert.equal(runtime.blocked, false);
});

test('discard invalidates an earlier detection completion', async (t) => {
  const pending = deferred();
  const runtime = setup(t, { fetcher: async (url) => (new URL(url).searchParams.get('task') === 'draft.detect'
    ? pending.promise
    : response({ discarded: true })) });
  const detection = runtime.candidates();
  await runtime.discard(runtime.binding.draftId);
  pending.resolve(response({ candidates: [], conflict: false }));
  await assert.rejects(detection, { name: 'AbortError' });
  assert.equal(runtime.state, 'discarded');
});

test('current detection conflict pauses capture instead of claiming ready', async (t) => {
  const runtime = setup(t, { fetcher: async () => response({ candidates: [], conflict: true }) });
  await runtime.candidates();
  assert.equal(runtime.state, 'conflict');
  assert.equal(runtime.blocked, true);
});

test('default browser transport keeps the Window receiver for detection and preservation', async (t) => {
  const sent = [];
  t.mock.method(globalThis, 'fetch', function browserFetch(url, options) {
    assert.equal(this, globalThis, 'Window.fetch requires its native receiver');
    const operation = new URL(url).searchParams.get('task');
    sent.push(operation);
    assert.equal(options.headers['X-CSRF-Token'], 'native-token');
    return Promise.resolve(operation === 'draft.detect'
      ? response({ candidates: [], conflict: false })
      : acknowledgement(JSON.parse(options.body).client_revision));
  });
  const runtime = setup(t, { fetcher: undefined });
  assert.deepEqual(await runtime.candidates(), { candidates: [], conflict: false });
  assert.equal(runtime.state, 'clean');
  runtime.changed();
  assert.equal(await runtime.flush(), true);
  assert.equal(runtime.state, 'preserved');
  assert.deepEqual(sent, ['draft.detect', 'draft.preserve']);
});

test('explicit discovery paging forwards an opaque cursor without writing a draft', async (t) => {
  const cursor = 'b'.repeat(32);
  let sent;
  const runtime = setup(t, { fetcher: async (url, request) => {
    assert.equal(new URL(url).searchParams.get('task'), 'draft.detect');
    sent = JSON.parse(request.body);
    return response({ candidates: [], next: null, conflict: false });
  } });
  const result = await runtime.candidates(cursor);
  assert.deepEqual(sent, { draft_id: 'a'.repeat(32), cursor });
  assert.equal(result.next, null);
  assert.equal(runtime.revision, 0);
  assert.equal(runtime.pending, false);
});

test('invalid next-page metadata never claims recovery readiness', async (t) => {
  const runtime = setup(t, { fetcher: async () => response({
    candidates: [], next: 'not-a-draft-id', conflict: false,
  }) });
  await assert.rejects(runtime.candidates(), { code: 'service_unavailable' });
  assert.equal(runtime.state, 'offline');
});

test('an unchanged initial creation capture never becomes a recovery payload', async (t) => {
  let requests = 0;
  const runtime = setup(t, {
    capture: () => ({ title: '', articletext: '' }),
    baseline: () => ({ title: '', articletext: '' }),
    fetcher: async () => {
      requests += 1;
      return acknowledgement(1);
    },
  });
  runtime.changed();
  assert.equal(await runtime.flush(), false);
  assert.equal(requests, 0);
  assert.equal(runtime.pending, false);
  assert.equal(runtime.state, 'clean');
  assert.equal(runtime.lastPreserved, null);
});

test('creation capture after genuine edits and returning to defaults remains recoverable', async (t) => {
  let value = 'unfinished';
  const snapshots = [];
  const runtime = setup(t, { capture: () => ({ title: value }), baseline: () => ({ title: '' }),
    fetcher: async (url, request) => {
      const snapshot = JSON.parse(request.body);
      snapshots.push(snapshot);
      return acknowledgement(snapshot.client_revision);
    } });
  runtime.changed();
  await runtime.flush();
  value = '';
  runtime.changed();
  await runtime.flush();
  assert.deepEqual(snapshots.map((snapshot) => snapshot.payload), [{ title: 'unfinished' }, { title: '' }]);
});

const creationBinding = (draftId = 'a'.repeat(32)) => ({
  creating: true, context: 'com_content.article', draftId,
});
const historyFixture = (state = null) => ({
  state,
  replaceState(next, title) {
    assert.equal(title, '');
    this.state = structuredClone(next);
  },
});

test('fresh New ignores old creation references and preserves native history state', () => {
  const history = historyFixture({ native: { scroll: 20 },
    joomlaAutosaveCreation: { context: 'com_content.article', draftId: 'b'.repeat(32), acknowledgedId: 'b'.repeat(32) } });
  const session = creationRecovery(creationBinding(), history, 'navigate');
  assert.deepEqual(session.sources, {});
  assert.deepEqual(history.state, { native: { scroll: 20 } });
  assert.equal(history.state.joomlaAutosaveCreation, undefined);
});

test('creation reload automatically identifies its pending and acknowledged instances', () => {
  const history = historyFixture();
  const first = creationRecovery(creationBinding(), history, 'navigate');
  assert.equal(history.state, null);
  assert.equal(first.attempted(), true);
  assert.equal(first.acknowledged(), true);
  const reload = creationRecovery(creationBinding('b'.repeat(32)), history, 'reload');
  assert.deepEqual(reload.sources, { creation_id: 'a'.repeat(32), acknowledged_id: 'a'.repeat(32) });
  const anotherReload = creationRecovery(creationBinding('c'.repeat(32)), history, 'reload');
  assert.deepEqual(anotherReload.sources, reload.sources);
  assert.equal(history.state.joomlaAutosaveCreation.draftId, 'a'.repeat(32));
});

test('an uncertain later write retains the last acknowledged creation reference', () => {
  const history = historyFixture();
  const first = creationRecovery(creationBinding(), history, 'navigate');
  first.attempted();
  first.acknowledged();
  const reload = creationRecovery(creationBinding('b'.repeat(32)), history, 'reload');
  reload.attempted();
  const again = creationRecovery(creationBinding('c'.repeat(32)), history, 'reload');
  assert.deepEqual(again.sources, { creation_id: 'b'.repeat(32), acknowledged_id: 'a'.repeat(32) });
  reload.acknowledged();
  assert.deepEqual(creationRecovery(creationBinding('d'.repeat(32)), history, 'reload').sources,
    { creation_id: 'b'.repeat(32), acknowledged_id: 'b'.repeat(32) });
});

test('creation references are tab-entry scoped and never attach to other contexts', () => {
  const history = historyFixture();
  const first = creationRecovery(creationBinding(), history, 'navigate');
  first.attempted();
  first.acknowledged();
  assert.deepEqual(creationRecovery(creationBinding('b'.repeat(32)), historyFixture(), 'reload').sources, {});
  assert.deepEqual(creationRecovery({ ...creationBinding(), context: 'another.context' }, history, 'reload').sources, {});
  assert.equal(creationRecovery({ creating: false }, history, 'reload'), null);
});

test('invalid references and unavailable history degrade without throwing or storing content', () => {
  const history = historyFixture({ native: 'preserved',
    joomlaAutosaveCreation: { context: 'com_content.article', draftId: '<invalid>', acknowledgedId: 'bad' } });
  assert.deepEqual(creationRecovery(creationBinding(), history, 'reload').sources, {});
  const broken = { state: null,
    replaceState() {
      throw new DOMException('History denied');
    } };
  assert.equal(creationRecovery(creationBinding(), broken, 'navigate').attempted(), false);
  assert.equal(creationRecovery(creationBinding(), historyFixture('native scalar'), 'reload'), false);
  const session = creationRecovery(creationBinding(), history, 'reload');
  session.attempted();
  session.acknowledged();
  assert.deepEqual(Object.keys(history.state.joomlaAutosaveCreation).sort(), ['acknowledgedId', 'context', 'draftId']);
  assert.equal(history.state.native, 'preserved');
});

test('unchanged submit-time editor notifications cannot create a revision beyond native retirement', async (t) => {
  const request = deferred();
  const bodies = [];
  const runtime = setup(t, { capture: () => ({ title: 'submitted', articletext: 'body' }),
    fetcher: async (url, options) => {
      bodies.push(JSON.parse(options.body));
      return request.promise;
    } });
  const submitted = runtime.submitted();
  const pending = runtime.inFlight;
  runtime.changed();
  request.resolve(acknowledgement(submitted));
  await pending;
  assert.equal(await runtime.flush(), false);
  assert.equal(bodies.length, 1);
  assert.equal(bodies[0].client_revision, submitted);
  assert.equal(runtime.pending, false);
});

test('a submitted snapshot queued behind an earlier write keeps the exact native sequence', async (t) => {
  const earlier = deferred();
  const bodies = [];
  let value = 'earlier';
  const runtime = setup(t, { capture: () => ({ title: value }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      return bodies.length === 1 ? earlier.promise : acknowledgement(body.client_revision);
    } });
  runtime.changed();
  const pending = runtime.flush();
  value = 'submitted';
  const submitted = runtime.submitted();
  runtime.changed();
  earlier.resolve(acknowledgement(1));
  await pending;
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.client_revision), [1, submitted]);
  assert.equal(bodies[1].payload.title, 'submitted');
  assert.equal(runtime.pending, false);
});

test('actual later edits and later undo remain separately recoverable after submission', async (t) => {
  const bodies = [];
  let value = 'submitted';
  const runtime = setup(t, { capture: () => ({ title: value }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      return acknowledgement(body.client_revision);
    } });
  const submitted = runtime.submitted();
  await runtime.inFlight;
  value = 'later edit';
  runtime.changed();
  await runtime.flush();
  value = 'submitted';
  runtime.changed();
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.payload.title), ['submitted', 'later edit', 'submitted']);
  assert.deepEqual(bodies.map((body) => body.client_revision), [submitted, submitted + 1, submitted + 2]);
});

test('failed native-save draft transport still retries its exact submitted snapshot', async (t) => {
  const bodies = [];
  const runtime = setup(t, { capture: () => ({ title: 'submitted' }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      if (bodies.length === 1) throw new TypeError('Transport unavailable');
      return acknowledgement(body.client_revision);
    } });
  const submitted = runtime.submitted();
  await runtime.inFlight;
  runtime.changed();
  assert.equal(await runtime.retry(), true);
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.client_revision), [submitted, submitted]);
  assert.equal(runtime.pending, false);
});

test('a late retired submission never claims draft acknowledgement or writes unchanged values again', async (t) => {
  const request = deferred();
  const bodies = [];
  const runtime = setup(t, { capture: () => ({ title: 'submitted' }),
    fetcher: async (url, options) => {
      bodies.push(JSON.parse(options.body));
      return request.promise;
    } });
  runtime.submitted();
  const pending = runtime.inFlight;
  runtime.changed();
  request.resolve({ ok: false, json: async () => ({ success: false, error: { code: 'draft_retired' } }) });
  await pending;
  assert.equal(await runtime.flush(), false);
  assert.equal(bodies.length, 1);
  assert.equal(runtime.pending, false);
  assert.equal(runtime.state, 'clean');
  assert.equal(runtime.lastPreserved, null);
});

test('genuine edits while submission waits behind an earlier write retain a higher sequence', async (t) => {
  const earlier = deferred();
  const bodies = [];
  let value = 'earlier';
  const runtime = setup(t, { capture: () => ({ title: value }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      return bodies.length === 1 ? earlier.promise : acknowledgement(body.client_revision);
    } });
  runtime.changed();
  const pending = runtime.flush();
  value = 'submitted';
  const submitted = runtime.submitted();
  value = 'real later edit';
  runtime.changed();
  earlier.resolve(acknowledgement(1));
  await pending;
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.client_revision), [1, submitted + 1]);
  assert.equal(bodies[1].payload.title, 'real later edit');
  assert.equal(runtime.state, 'preserved');
});

test('unchanged native array selections do not manufacture a draft for fresh creation', async (t) => {
  const runtime = setup(t, {
    baseline: () => ({ rules: ['1', '3'] }),
    capture: () => ({ rules: ['1', '3'] }),
    fetcher: async () => { throw new Error('Untouched creation must not write'); },
  });
  runtime.changed();
  assert.equal(await runtime.flush(), false);
  assert.equal(runtime.state, 'clean');
});

test('unchanged array notifications during native Save keep the submitted retirement floor', async (t) => {
  const bodies = [];
  let selected = ['1', '3'];
  const runtime = setup(t, {
    capture: () => ({ rules: [...selected] }),
    fetcher: async (url, request) => {
      const body = JSON.parse(request.body);
      bodies.push(body);
      return acknowledgement(body.client_revision);
    },
  });
  const submitted = runtime.submitted();
  await runtime.inFlight;
  runtime.changed();
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.client_revision), [submitted]);
  selected = ['1'];
  runtime.changed();
  await runtime.flush();
  assert.equal(bodies.length, 2);
  assert.deepEqual(bodies[1].payload.rules, ['1']);
});

test('continuous edits reach the dirty deadline without an idle periodic write', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
  const bodies = [];
  const runtime = setup(t, { debounce: 600, interval: 1000, fetcher: async (url, request) => {
    bodies.push(JSON.parse(request.body));
    return acknowledgement(bodies.at(-1).client_revision);
  } });
  t.mock.timers.tick(3000);
  assert.equal(bodies.length, 0);
  for (let index = 0; index < 10; index += 1) {
    runtime.changed();
    t.mock.timers.tick(100);
  }
  await runtime.inFlight;
  assert.equal(bodies.length, 1);
  t.mock.timers.tick(5000);
  assert.equal(bodies.length, 1);
});

test('empty binding resumption is not an acknowledgement and preserves the latest values', async (t) => {
  const resumed = deferred();
  const bodies = [];
  let value = 'first';
  const runtime = setup(t, { capture: () => ({ title: value }), fetcher: async (url, request) => {
    const operation = new URL(url).searchParams.get('task');
    bodies.push({ operation, body: JSON.parse(request.body) });
    if (bodies.length === 1) return responseError('draft_not_found');
    if (operation === 'draft.resume') return resumed.promise;
    return acknowledgement(bodies.at(-1).body.client_revision);
  } });
  runtime.changed();
  const writing = runtime.flush();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(runtime.lastPreserved, null);
  assert.equal(bodies.length, 2);
  value = 'edited during resumption';
  runtime.changed();
  resumed.resolve(response({ resumed: true, draft_id: 'a'.repeat(32), schema_version: 1 }));
  await writing;
  assert.equal(bodies.length, 3);
  assert.deepEqual(bodies[2].body.payload, { title: value });
  assert.equal(runtime.state, 'preserved');
});

const responseError = (code) => ({ ok: false, json: async () => ({ success: false, error: { code } }) });

for (const action of ['destroy', 'submit']) {
  test('late resumption cannot restart protection after ' + action, async (t) => {
    const resumed = deferred();
    const operations = [];
    const runtime = setup(t, { fetcher: async (url) => {
      const operation = new URL(url).searchParams.get('task');
      operations.push(operation);
      return operation === 'draft.resume' ? resumed.promise : responseError('draft_expired');
    } });
    runtime.changed();
    const writing = runtime.flush();
    await new Promise((resolve) => setImmediate(resolve));
    if (action === 'destroy') runtime.destroy();
    if (action === 'submit') runtime.submitted();
    resumed.resolve(response({ resumed: true, draft_id: 'a'.repeat(32), schema_version: 1 }));
    await writing;
    assert.deepEqual(operations, ['draft.preserve', 'draft.resume']);
    assert.equal(runtime.lastPreserved, null);
  });
}

for (const creating of [true, false]) {
  test('the shared writer refuses untouched new and existing native projections: creating=' + creating, async (t) => {
    const runtime = setup(t, {
      binding: { draftId: 'a'.repeat(32), version: 1, creating, endpoint: 'http://localhost/administrator/index.php?option=com_autosave&format=json' },
      capture: () => ({ title: '', rules: ['1', '3'] }),
      fetcher: async () => { throw new Error('Untouched native form must not preserve'); },
    });
    runtime.initialProjection = { title: '', rules: ['1', '3'] };
    runtime.changed();
    assert.equal(await runtime.flush(), false);
    assert.equal(runtime.state, 'clean');
    assert.equal(runtime.lastPreserved, null);
  });
}
