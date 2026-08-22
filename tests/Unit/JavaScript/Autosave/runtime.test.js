/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
  AUTOSAVE_DRAFT_EVENT,
  AUTOSAVE_STATE_EVENT,
  AutosaveRuntime,
} from '../../../../media_source/com_autosave/js/runtime.es6.js';

const settle = async (turns = 12) => {
  for (let index = 0; index < turns; index += 1) {
    await Promise.resolve();
  }
};

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((promiseResolve, promiseReject) => {
    resolve = promiseResolve;
    reject = promiseReject;
  });

  return { promise, resolve, reject };
};

class FakeClock {
  constructor() {
    this.time = 0;
    this.nextId = 1;
    this.timers = new Map();
  }

  now = () => this.time;

  setTimeout = (callback, delay) => {
    const id = this.nextId;
    this.nextId += 1;
    this.timers.set(id, { callback, at: this.time + Math.max(0, delay) });

    return id;
  };

  clearTimeout = (id) => {
    this.timers.delete(id);
  };

  async tick(milliseconds) {
    const target = this.time + milliseconds;

    while (true) {
      const due = [...this.timers.entries()]
        .filter(([, timer]) => timer.at <= target)
        .sort((first, second) => first[1].at - second[1].at || first[0] - second[0])[0];

      if (!due) {
        break;
      }

      const [id, timer] = due;
      this.timers.delete(id);
      this.time = timer.at;
      await timer.callback();
      await settle();
    }

    this.time = target;
    await settle();
  }
}

class FakeSource {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(name, callback) {
    if (!this.listeners.has(name)) {
      this.listeners.set(name, new Set());
    }

    this.listeners.get(name).add(callback);
  }

  removeEventListener(name, callback) {
    this.listeners.get(name)?.delete(callback);
  }

  emit(name) {
    [...(this.listeners.get(name) || [])].forEach((callback) => callback());
  }

  count() {
    return [...this.listeners.values()].reduce((total, listeners) => total + listeners.size, 0);
  }
}

class RecordingTarget {
  constructor() {
    this.events = [];
  }

  dispatchEvent(event) {
    this.events.push(event);

    return true;
  }
}

const identity = {
  continuation_id: 'continuation',
  generation_id: 'generation',
  context: 'com_example.record',
  target_id: '42',
  base_revision: 'base-1',
  payload_schema_version: 1,
};

const candidate = {
  ...identity,
  current_base_revision: 'base-1',
  classification: 'current',
  client_revision: 3,
  updated_at: '2026-08-02T00:00:00Z',
  expires_at: '2026-08-09T00:00:00Z',
};

const recovered = {
  ...candidate,
  created_at: '2026-08-01T00:00:00Z',
  payload: { title: 'Recovered' },
};

const successfulOutcome = {
  operation_id: 'operation',
  context: identity.context,
  target_id: identity.target_id,
  continuation_id: identity.continuation_id,
  generation_id: identity.generation_id,
  intent: 'apply',
  outcome: 'successful',
  expected_base_revision: identity.base_revision,
  final_target_id: identity.target_id,
  final_base_revision: 'base-2',
  failure_code: null,
  created_at: '2026-08-05T00:00:00Z',
  updated_at: '2026-08-05T00:00:01Z',
  expires_at: '2026-08-06T00:00:00Z',
  completed_at: '2026-08-05T00:00:01Z',
};

const apiFailure = (classification, options = {}) => Object.assign(
  new Error('API failure'),
  {
    code: options.code || classification.replaceAll('-', '_'),
    classification,
    retryable: options.retryable === true,
    outcomeUnknown: options.outcomeUnknown === true,
    retryAfter: options.retryAfter ?? null,
  },
);

const createApi = (overrides = {}) => {
  const calls = {
    initialize: [],
    preserve: [],
    detect: [],
    read: [],
    discard: [],
    prepareCanonicalAction: [],
    getCanonicalActionOutcome: [],
  };
  const api = { calls };
  const defaults = {
    initialize: async () => identity,
    preserve: async () => ({ status: 'accepted' }),
    detect: async () => null,
    read: async () => recovered,
    discard: async () => ({ status: 'discarded' }),
    prepareCanonicalAction: async () => ({
      operation_id: 'operation',
      intent: 'apply',
      outcome: 'pending',
      expires_at: '2026-08-06T00:00:00Z',
    }),
    getCanonicalActionOutcome: async () => successfulOutcome,
  };

  Object.keys(calls).forEach((operation) => {
    api[operation] = async (request, options) => {
      calls[operation].push({ request, options });

      return (overrides[operation] || defaults[operation])(request, options);
    };
  });

  return api;
};

const createAdapter = (overrides = {}) => {
  const adapter = {
    captures: 0,
    applies: 0,
    unsubscribes: 0,
    destroys: 0,
    listener: null,
    value: { title: 'Draft' },
    subscribe(listener) {
      this.listener = listener;
      let subscribed = true;

      return () => {
        if (subscribed) {
          subscribed = false;
          this.unsubscribes += 1;
          this.listener = null;
        }
      };
    },
    async capture() {
      this.captures += 1;

      return this.value;
    },
    async apply(payload) {
      this.applies += 1;
      this.appliedPayload = payload;
    },
    destroy() {
      this.destroys += 1;
    },
    edit() {
      this.listener?.();
    },
    ...overrides,
  };

  return adapter;
};

const createRuntime = ({
  api = createApi(),
  adapter = createAdapter(),
  detectOnStart = false,
  online = true,
  hidden = false,
  configuration = {},
  identities = ['document-id', 'initialization-key', 'successor-initialization-key'],
} = {}) => {
  const clock = new FakeClock();
  const onlineSource = new FakeSource();
  const visibilitySource = new FakeSource();
  const eventTarget = new RecordingTarget();
  let currentOnline = online;
  let currentHidden = hidden;
  let identityIndex = 0;
  const runtime = new AutosaveRuntime({
    apiClient: api,
    adapter,
    context: 'com_example.record',
    targetId: '42',
    schemaVersion: 1,
    eventTarget,
    eventFactory: (name, detail) => ({ type: name, detail }),
    clock,
    identityFactory: () => identities[identityIndex++],
    onlineSource,
    visibilitySource,
    isOnline: () => currentOnline,
    isHidden: () => currentHidden,
    configuration: {
      debounceInterval: 10,
      maximumDirtyAge: 40,
      retryInitialDelay: 5,
      retryMaximumDelay: 20,
      maximumRetryAttempts: 3,
      detectOnStart,
      ...configuration,
    },
  });

  return {
    runtime,
    api,
    adapter,
    clock,
    eventTarget,
    onlineSource,
    visibilitySource,
    setOnline(value) {
      currentOnline = value;
      onlineSource.emit(value ? 'online' : 'offline');
    },
    setHidden(value) {
      currentHidden = value;
      visibilitySource.emit('visibilitychange');
    },
  };
};

test('start is idempotent and an untouched adapter never initializes or captures', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  harness.runtime.start();
  await harness.clock.tick(100);

  assert.equal(harness.adapter.captures, 0);
  assert.equal(harness.api.calls.initialize.length, 0);
  assert.equal(harness.onlineSource.count(), 2);
  assert.equal(harness.visibilitySource.count(), 1);
  assert.equal(harness.runtime.state.status, 'clean');
});

test('detection is read-only and does not capture, initialize, or restore automatically', async () => {
  const harness = createRuntime({ detectOnStart: true });
  harness.runtime.start();
  await settle();

  assert.equal(harness.api.calls.detect.length, 1);
  assert.equal(harness.api.calls.initialize.length, 0);
  assert.equal(harness.adapter.captures, 0);
  assert.equal(harness.adapter.applies, 0);
  assert.equal(harness.runtime.state.status, 'clean');
});

test('changes are cheap until debounce and one eligible snapshot gets revision one', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  harness.adapter.edit();
  harness.adapter.edit();

  assert.equal(harness.adapter.captures, 0);
  assert.equal(harness.api.calls.initialize.length, 0);
  assert.equal(harness.runtime.state.changeSequence, 2);
  await harness.clock.tick(9);
  assert.equal(harness.adapter.captures, 0);
  await harness.clock.tick(1);

  assert.equal(harness.adapter.captures, 1);
  assert.equal(harness.api.calls.initialize.length, 1);
  assert.equal(harness.api.calls.preserve.length, 1);
  assert.equal(harness.api.calls.preserve[0].request.client_revision, 1);
  assert.equal(harness.runtime.state.lastAcknowledgedRevision, 1);
  assert.equal(harness.runtime.state.status, 'preserved');
});

test('continuous changes reset debounce but maximum dirty age forces capture', async () => {
  const harness = createRuntime({
    configuration: { debounceInterval: 15, maximumDirtyAge: 40 },
  });
  harness.runtime.start();
  harness.adapter.edit();

  for (let elapsed = 0; elapsed < 36; elapsed += 9) {
    await harness.clock.tick(9);
    harness.adapter.edit();
    assert.equal(harness.adapter.captures, 0);
  }

  await harness.clock.tick(4);
  assert.equal(harness.adapter.captures, 1);
  assert.equal(harness.api.calls.preserve.length, 1);
});

test('capture failure allocates no revision and explicit retry can recover', async () => {
  let fail = true;
  const adapter = createAdapter({
    async capture() {
      this.captures += 1;

      if (fail) {
        throw new Error('editor unavailable');
      }

      return this.value;
    },
  });
  const harness = createRuntime({ adapter });
  harness.runtime.start();
  adapter.edit();
  await harness.clock.tick(10);

  assert.equal(harness.runtime.state.clientRevision, 0);
  assert.equal(harness.api.calls.initialize.length, 0);
  assert.equal(harness.runtime.state.error.code, 'adapter_capture_failed');

  fail = false;
  assert.equal(harness.runtime.retry(), true);
  await settle();
  assert.equal(harness.api.calls.preserve[0].request.client_revision, 1);
});

test('initialization outcome-unknown retries reuse one idempotency key and pending snapshot', async () => {
  let attempts = 0;
  const api = createApi({
    initialize: async () => {
      attempts += 1;

      if (attempts === 1) {
        throw apiFailure('network-failure', { retryable: true, outcomeUnknown: true });
      }

      return identity;
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);

  assert.equal(harness.runtime.state.status, 'retry-waiting');
  assert.equal(harness.api.calls.preserve.length, 0);
  await harness.clock.tick(5);

  assert.equal(api.calls.initialize.length, 2);
  assert.equal(
    api.calls.initialize[0].request.initialization_key,
    api.calls.initialize[1].request.initialization_key,
  );
  assert.equal(api.calls.preserve.length, 1);
  assert.equal(api.calls.preserve[0].request.client_revision, 1);
});

test('one preserve stays active and an older acknowledgement cannot clear newer edits', async () => {
  const firstPreserve = deferred();
  const secondPreserve = deferred();
  let preserves = 0;
  const api = createApi({
    preserve: async () => {
      preserves += 1;

      return preserves === 1 ? firstPreserve.promise : secondPreserve.promise;
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);
  assert.equal(api.calls.preserve.length, 1);

  harness.adapter.value = { title: 'Newer' };
  harness.adapter.edit();
  await harness.clock.tick(20);
  assert.equal(api.calls.preserve.length, 1);
  assert.equal(api.calls.preserve[0].options.signal.aborted, false);
  assert.equal(harness.runtime.state.dirty, true);

  firstPreserve.resolve({ status: 'accepted' });
  await settle();
  assert.equal(harness.runtime.state.lastAcknowledgedRevision, 1);
  assert.equal(harness.runtime.state.dirty, true);
  await harness.clock.tick(1);

  assert.equal(api.calls.preserve.length, 2);
  assert.equal(api.calls.preserve[1].request.client_revision, 2);
  assert.deepEqual(api.calls.preserve[1].request.payload, { title: 'Newer' });
  secondPreserve.resolve({ status: 'accepted' });
  await settle();
  assert.equal(harness.runtime.state.lastAcknowledgedRevision, 2);
  assert.equal(harness.runtime.state.dirty, false);
});

test('an uncertain preserve retry uses the exact revision and immutable payload', async () => {
  let attempts = 0;
  const api = createApi({
    preserve: async () => {
      attempts += 1;

      if (attempts === 1) {
        throw apiFailure('request-timeout', { retryable: true, outcomeUnknown: true });
      }

      return { status: 'idempotent' };
    },
  });
  const adapter = createAdapter();
  const harness = createRuntime({ api, adapter });
  harness.runtime.start();
  adapter.edit();
  await harness.clock.tick(10);

  assert.equal(harness.runtime.state.status, 'retry-waiting');
  assert.notEqual(harness.runtime.state.status, 'preserved');
  adapter.value.title = 'Mutated live object';
  await harness.clock.tick(5);

  assert.equal(api.calls.preserve.length, 2);
  assert.equal(api.calls.preserve[0].request.client_revision, api.calls.preserve[1].request.client_revision);
  assert.strictEqual(api.calls.preserve[0].request.payload, api.calls.preserve[1].request.payload);
  assert.deepEqual(api.calls.preserve[1].request.payload, { title: 'Draft' });
  assert.equal(harness.runtime.state.status, 'preserved');
  assert.equal(harness.runtime.state.lastSuccessfulAt, 15);
});

test('retry exhaustion pauses dirty work and a later edit resumes the same snapshot first', async () => {
  let failing = true;
  const api = createApi({
    preserve: async () => {
      if (failing) {
        throw apiFailure('temporary-server-failure', { retryable: true });
      }

      return { status: 'accepted' };
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);
  await harness.clock.tick(5);
  await harness.clock.tick(10);

  assert.equal(harness.runtime.state.status, 'paused');
  assert.equal(harness.runtime.state.dirty, true);
  const revision = api.calls.preserve.at(-1).request.client_revision;
  failing = false;
  harness.adapter.edit();
  await settle();

  assert.equal(api.calls.preserve.at(-1).request.client_revision, revision);
  assert.equal(harness.runtime.state.lastAcknowledgedRevision, revision);
});

test('authentication, permission, conflict, and terminal failures enter deterministic blocked states', async (t) => {
  const cases = [
    ['authentication-required', 'authentication-required'],
    ['permission-denied', 'error'],
    ['conflict', 'conflict'],
    ['terminal-generation', 'terminal'],
  ];

  for (const [classification, expectedStatus] of cases) {
    await t.test(classification, async () => {
      const api = createApi({
        preserve: async () => {
          throw apiFailure(classification);
        },
      });
      const harness = createRuntime({ api });
      harness.runtime.start();
      harness.adapter.edit();
      await harness.clock.tick(10);

      assert.equal(harness.runtime.state.status, expectedStatus);
      const callCount = api.calls.preserve.length;
      harness.adapter.edit();
      await harness.clock.tick(100);
      assert.equal(api.calls.preserve.length, callCount);
    });
  }
});

test('offline edits remain dirty and reconnect resumes without duplicate requests', async () => {
  const harness = createRuntime({ online: false });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(100);

  assert.equal(harness.runtime.state.status, 'offline');
  assert.equal(harness.runtime.state.dirty, true);
  assert.equal(harness.api.calls.initialize.length, 0);

  harness.setOnline(true);
  await settle();
  assert.equal(harness.api.calls.initialize.length, 1);
  assert.equal(harness.api.calls.preserve.length, 1);
  assert.equal(harness.runtime.state.status, 'preserved');
});

test('reconnect resumes pending dirty retry with the same snapshot and revision', async () => {
  let attempts = 0;
  const api = createApi({
    preserve: async () => {
      attempts += 1;

      if (attempts === 1) {
        throw apiFailure('network-failure', { retryable: true, outcomeUnknown: true });
      }

      return { status: 'idempotent' };
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);
  assert.equal(harness.runtime.state.status, 'retry-waiting');
  assert.equal(harness.runtime.state.dirty, true);

  harness.setOnline(false);
  assert.equal(harness.runtime.state.status, 'offline');
  harness.setOnline(true);
  await settle();

  assert.equal(api.calls.preserve.length, 2);
  assert.equal(
    api.calls.preserve[0].request.client_revision,
    api.calls.preserve[1].request.client_revision,
  );
  assert.strictEqual(api.calls.preserve[0].request.payload, api.calls.preserve[1].request.payload);
  assert.equal(harness.runtime.state.status, 'preserved');
  assert.equal(harness.runtime.state.dirty, false);
});

test('reconnect restores unresolved recovery state through a separate pair EventTarget', async () => {
  const api = createApi({ detect: async () => candidate });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  await settle();

  assert.notStrictEqual(harness.onlineSource, harness.eventTarget);
  assert.equal(harness.runtime.state.status, 'recovery-required');
  const detectedCandidate = harness.runtime.state.recoveryCandidate;

  harness.setOnline(false);
  assert.equal(harness.runtime.state.status, 'offline');
  assert.deepEqual(harness.runtime.state.recoveryCandidate, detectedCandidate);

  const reconnectEventCount = harness.eventTarget.events.length;
  harness.setOnline(true);
  await settle();

  assert.equal(harness.runtime.state.status, 'recovery-required');
  assert.deepEqual(harness.runtime.state.recoveryCandidate, detectedCandidate);
  assert.equal(api.calls.read.length, 0);
  assert.equal(api.calls.discard.length, 0);
  assert.equal(harness.adapter.applies, 0);
  assert.equal(harness.eventTarget.events.length, reconnectEventCount + 1);

  harness.setOnline(true);
  await settle();
  assert.equal(harness.runtime.state.status, 'recovery-required');
  assert.deepEqual(harness.runtime.state.recoveryCandidate, detectedCandidate);
  assert.equal(harness.eventTarget.events.length, reconnectEventCount + 1);
});

test('reconnect with no pending work returns to the prior clean or preserved state', async (t) => {
  await t.test('clean', async () => {
    const harness = createRuntime();
    harness.runtime.start();
    harness.setOnline(false);
    assert.equal(harness.runtime.state.status, 'offline');

    harness.setOnline(true);
    await settle();
    assert.equal(harness.runtime.state.status, 'clean');
    assert.equal(harness.api.calls.initialize.length, 0);
    assert.equal(harness.api.calls.preserve.length, 0);
  });

  await t.test('preserved', async () => {
    const harness = createRuntime();
    harness.runtime.start();
    harness.adapter.edit();
    await harness.clock.tick(10);
    assert.equal(harness.runtime.state.status, 'preserved');

    harness.setOnline(false);
    assert.equal(harness.runtime.state.status, 'offline');
    harness.setOnline(true);
    await settle();
    assert.equal(harness.runtime.state.status, 'preserved');
    assert.equal(harness.api.calls.initialize.length, 1);
    assert.equal(harness.api.calls.preserve.length, 1);
  });
});

test('hidden visibility triggers immediate Fetch eligibility without concurrency', async () => {
  const preserve = deferred();
  const api = createApi({ preserve: async () => preserve.promise });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  harness.setHidden(true);
  await settle();

  assert.equal(api.calls.preserve.length, 1);
  harness.adapter.edit();
  harness.setHidden(true);
  await settle();
  assert.equal(api.calls.preserve.length, 1);
  preserve.resolve({ status: 'accepted' });
  await settle();
});

test('an edit while detection is pending remains tracked and candidate data is never applied or emitted', async () => {
  const detection = deferred();
  const api = createApi({ detect: async () => detection.promise });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  harness.adapter.edit();
  detection.resolve(candidate);
  await settle();

  assert.equal(harness.runtime.state.status, 'recovery-required');
  assert.equal(harness.runtime.state.dirty, true);
  assert.equal(harness.adapter.applies, 0);
  assert.equal(harness.api.calls.initialize.length, 0);
  const draftEvent = harness.eventTarget.events.find((event) => event.type === AUTOSAVE_DRAFT_EVENT);
  assert.equal(draftEvent.detail.recoveryCandidate.localEdits, true);
  assert.equal(JSON.stringify(draftEvent).includes('continuation'), false);
  assert.equal(JSON.stringify(draftEvent).includes('Recovered'), false);
});

test('restore is explicit, suppresses adapter change events, validates identity, and binds recovered revision', async () => {
  const api = createApi({ detect: async () => candidate });
  const adapter = createAdapter({
    async apply(payload) {
      this.applies += 1;
      this.appliedPayload = payload;
      this.listener?.();
      await Promise.resolve();
      this.listener?.();
    },
  });
  const harness = createRuntime({ api, adapter, detectOnStart: true });
  harness.runtime.start();
  await settle();
  assert.equal(adapter.applies, 0);

  assert.equal(await harness.runtime.restoreDetectedDraft(), true);
  assert.equal(api.calls.read.length, 1);
  assert.equal(adapter.applies, 1);
  assert.deepEqual(adapter.appliedPayload, { title: 'Recovered' });
  assert.equal(harness.runtime.state.changeSequence, 0);
  assert.equal(harness.runtime.state.dirty, false);
  assert.equal(harness.runtime.state.lastAcknowledgedRevision, 3);

  adapter.value = { title: 'After recovery' };
  adapter.edit();
  await harness.clock.tick(10);
  assert.equal(api.calls.initialize.length, 0);
  assert.equal(api.calls.preserve.at(-1).request.continuation_id, 'continuation');
  assert.equal(api.calls.preserve.at(-1).request.client_revision, 4);
});

test('apply failure or malformed recovered identity never claims restoration', async (t) => {
  await t.test('apply failure', async () => {
    const api = createApi({ detect: async () => candidate });
    const adapter = createAdapter({
      async apply() {
        throw new Error('apply failed');
      },
    });
    const harness = createRuntime({ api, adapter, detectOnStart: true });
    harness.runtime.start();
    await settle();

    assert.equal(await harness.runtime.restoreDetectedDraft(), false);
    assert.equal(harness.runtime.state.status, 'recovery-required');
    assert.notEqual(harness.runtime.state.lastAcknowledgedRevision, 3);
    assert.ok(harness.runtime.state.recoveryCandidate);
  });

  await t.test('identity mismatch', async () => {
    const api = createApi({
      detect: async () => candidate,
      read: async () => ({ ...recovered, generation_id: 'other' }),
    });
    const harness = createRuntime({ api, detectOnStart: true });
    harness.runtime.start();
    await settle();

    assert.equal(await harness.runtime.restoreDetectedDraft(), false);
    assert.equal(harness.adapter.applies, 0);
    assert.equal(harness.runtime.state.status, 'recovery-required');
  });
});

test('discard failure keeps recovery pending and success preserves local dirty work in a new identity', async () => {
  let fail = true;
  const api = createApi({
    detect: async () => candidate,
    discard: async () => {
      if (fail) {
        throw apiFailure('permission-denied');
      }

      return { status: 'discarded' };
    },
  });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  await settle();
  harness.adapter.edit();

  assert.equal(await harness.runtime.discardDetectedDraft(), false);
  assert.ok(harness.runtime.state.recoveryCandidate);
  assert.equal(harness.runtime.state.dirty, true);

  fail = false;
  assert.equal(await harness.runtime.discardDetectedDraft(), true);
  await harness.clock.tick(10);
  assert.equal(harness.runtime.state.recoveryCandidate, null);
  assert.equal(api.calls.initialize.length, 1);
  assert.equal(api.calls.preserve.length, 1);
});

test('keepCurrent neither discards nor binds the candidate and offers it only once', async () => {
  const api = createApi({ detect: async () => candidate });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  harness.adapter.edit();
  await settle();

  assert.equal(harness.runtime.keepCurrent(), true);
  await harness.clock.tick(10);
  assert.equal(api.calls.discard.length, 0);
  assert.equal(api.calls.initialize.length, 1);
  assert.equal(api.calls.preserve[0].request.continuation_id, 'continuation');
  assert.equal(harness.runtime.keepCurrent(), false);
  assert.equal(harness.eventTarget.events.filter((event) => event.type === AUTOSAVE_DRAFT_EVENT).length, 1);
});

test('authentication during detection and recovery remains explicit and retryable only by caller action', async (t) => {
  await t.test('detect', async () => {
    const api = createApi({
      detect: async () => {
        throw apiFailure('authentication-required');
      },
    });
    const harness = createRuntime({ api, detectOnStart: true });
    harness.runtime.start();
    await settle();
    assert.equal(harness.runtime.state.status, 'authentication-required');
  });

  for (const operation of ['read', 'discard']) {
    await t.test(operation, async () => {
      const api = createApi({
        detect: async () => candidate,
        [operation]: async () => {
          throw apiFailure('authentication-required');
        },
      });
      const harness = createRuntime({ api, detectOnStart: true });
      harness.runtime.start();
      await settle();

      const result = operation === 'read'
        ? await harness.runtime.restoreDetectedDraft()
        : await harness.runtime.discardDetectedDraft();
      assert.equal(result, false);
      assert.equal(harness.runtime.state.status, 'authentication-required');
      assert.ok(harness.runtime.state.recoveryCandidate);
    });
  }
});

test('an outcome-unknown discard paused by offline state resumes the exact candidate on reconnect', async () => {
  const firstDiscard = deferred();
  let attempts = 0;
  const api = createApi({
    detect: async () => candidate,
    discard: async () => {
      attempts += 1;

      return attempts === 1 ? firstDiscard.promise : { status: 'idempotent' };
    },
  });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  await settle();
  const result = harness.runtime.discardDetectedDraft();
  await settle();
  harness.setOnline(false);
  firstDiscard.reject(apiFailure('network-failure', { retryable: true, outcomeUnknown: true }));
  assert.equal(await result, false);
  assert.equal(harness.runtime.state.status, 'offline');

  harness.setOnline(true);
  await settle();
  assert.equal(api.calls.discard.length, 2);
  assert.deepEqual(api.calls.discard[0].request, api.calls.discard[1].request);
  assert.equal(harness.runtime.state.recoveryCandidate, null);
});

test('a terminal unbound recovery candidate can be ignored without blocking a new continuation', async () => {
  const api = createApi({
    detect: async () => candidate,
    read: async () => {
      throw apiFailure('terminal-generation');
    },
  });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  await settle();
  harness.adapter.edit();

  assert.equal(await harness.runtime.restoreDetectedDraft(), false);
  assert.equal(harness.runtime.state.status, 'recovery-required');
  assert.equal(harness.runtime.keepCurrent(), true);
  await harness.clock.tick(10);
  assert.equal(api.calls.initialize.length, 1);
  assert.equal(api.calls.preserve.length, 1);
});

test('destroy is idempotent, aborts owned work, removes resources, and ignores late results', async () => {
  const detection = deferred();
  const api = createApi({ detect: async () => detection.promise });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  await settle();
  const signal = api.calls.detect[0].options.signal;
  const eventCount = harness.eventTarget.events.length;

  harness.runtime.destroy();
  harness.runtime.destroy();
  assert.equal(signal.aborted, true);
  assert.equal(harness.adapter.unsubscribes, 1);
  assert.equal(harness.adapter.destroys, 1);
  assert.equal(harness.onlineSource.count(), 0);
  assert.equal(harness.visibilitySource.count(), 0);
  assert.equal(harness.clock.timers.size, 0);
  assert.equal(harness.runtime.state.status, 'destroyed');
  assert.equal(harness.eventTarget.events.length, eventCount + 1);

  harness.setOnline(false);
  harness.setOnline(true);
  assert.equal(harness.runtime.state.status, 'destroyed');
  assert.equal(harness.eventTarget.events.length, eventCount + 1);

  detection.resolve(candidate);
  await settle();
  assert.equal(harness.eventTarget.events.length, eventCount + 1);
  assert.equal(harness.adapter.applies, 0);
});

test('destruction during preserve never acknowledges an aborted or late write', async () => {
  const preservation = deferred();
  const api = createApi({ preserve: async () => preservation.promise });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);
  const eventCount = harness.eventTarget.events.length;

  harness.runtime.destroy();
  assert.equal(api.calls.preserve[0].options.signal.aborted, true);
  preservation.resolve({ status: 'accepted' });
  await settle();
  assert.equal(harness.runtime.state.lastAcknowledgedRevision, 0);
  assert.equal(harness.runtime.state.lastSuccessfulAt, null);
  assert.equal(harness.eventTarget.events.length, eventCount + 1);
});

test('separate runtime instances receive independent document and initialization identities', () => {
  const firstIds = ['document-one', 'init-one'];
  const secondIds = ['document-two', 'init-two'];
  const first = createRuntime({ identities: firstIds });
  const second = createRuntime({ identities: secondIds });

  first.runtime.start();
  second.runtime.start();
  first.adapter.edit();
  second.adapter.edit();

  assert.notEqual(first.runtime.runtimeIdentity, second.runtime.runtimeIdentity);
  assert.notEqual(first.runtime.initializationKey, second.runtime.initializationKey);
  assert.equal(first.runtime.identity, null);
  assert.equal(second.runtime.identity, null);
});

test('duplicate-tab simulation produces different server continuations', async () => {
  const apiFor = () => createApi({
    initialize: async (request) => ({
      ...identity,
      continuation_id: `continuation-${request.initialization_key}`,
      generation_id: `generation-${request.initialization_key}`,
    }),
  });
  const first = createRuntime({ api: apiFor(), identities: ['document-one', 'init-one'] });
  const second = createRuntime({ api: apiFor(), identities: ['document-two', 'init-two'] });
  first.runtime.start();
  second.runtime.start();
  first.adapter.edit();
  second.adapter.edit();
  await first.clock.tick(10);
  await second.clock.tick(10);

  assert.notEqual(first.runtime.identity.continuationId, second.runtime.identity.continuationId);
  assert.notEqual(first.runtime.identity.generationId, second.runtime.identity.generationId);
});

test('canonical preparation initializes lazily, captures one exact snapshot and closes with a higher revision', async () => {
  const api = createApi();
  const adapter = createAdapter({ value: { title: 'Submitted' } });
  const harness = createRuntime({ api, adapter });
  harness.runtime.start();

  const prepared = await harness.runtime.prepareCanonicalAction('apply');

  assert.equal(adapter.captures, 1);
  assert.equal(api.calls.initialize.length, 1);
  assert.equal(api.calls.prepareCanonicalAction.length, 1);
  assert.deepEqual(api.calls.prepareCanonicalAction[0].request, {
    context: identity.context,
    target_id: identity.target_id,
    continuation_id: identity.continuation_id,
    generation_id: identity.generation_id,
    client_revision: 1,
    payload_schema_version: 1,
    payload: { title: 'Submitted' },
    intent: 'apply',
    expected_base_revision: identity.base_revision,
  });
  assert.deepEqual(prepared, {
    operationId: 'operation',
    intent: 'apply',
    submittedChangeSequence: 0,
  });
  assert.equal(harness.runtime.state.status, 'canonical-submitting');
});

test('canonical preparation waits for an in-flight preserve and late scheduling remains paused', async () => {
  const preservation = deferred();
  const api = createApi({ preserve: () => preservation.promise });
  const harness = createRuntime({ api });
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);
  const preparation = harness.runtime.prepareCanonicalAction('apply');
  await settle();

  assert.equal(api.calls.prepareCanonicalAction.length, 0);
  preservation.resolve({ status: 'accepted' });
  await preparation;

  assert.equal(api.calls.prepareCanonicalAction.length, 1);
  assert.equal(api.calls.preserve.length, 1);
});

test('uncertain canonical preparation retries the exact snapshot and revision idempotently', async () => {
  let attempts = 0;
  const api = createApi({
    prepareCanonicalAction: async () => {
      attempts += 1;

      if (attempts === 1) {
        throw apiFailure('network-failure', { retryable: true, outcomeUnknown: true });
      }

      return {
        operation_id: 'operation',
        intent: 'apply',
        outcome: 'pending',
        expires_at: '2026-08-06T00:00:00Z',
      };
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  const preparation = harness.runtime.prepareCanonicalAction('apply');
  await settle();
  assert.equal(api.calls.prepareCanonicalAction.length, 1);
  await harness.clock.tick(5);
  await preparation;

  assert.equal(api.calls.prepareCanonicalAction.length, 2);
  assert.deepEqual(
    api.calls.prepareCanonicalAction[0].request,
    api.calls.prepareCanonicalAction[1].request,
  );
});

test('offline and reconnect during canonical preparation preserve the canonical state and exact retry', async () => {
  const firstAttempt = deferred();
  let attempts = 0;
  const api = createApi({
    prepareCanonicalAction: async () => {
      attempts += 1;

      if (attempts === 1) {
        return firstAttempt.promise;
      }

      return {
        operation_id: 'operation',
        intent: 'apply',
        outcome: 'pending',
        expires_at: '2026-08-06T00:00:00Z',
      };
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  const preparation = harness.runtime.prepareCanonicalAction('apply');
  await settle();

  harness.setOnline(false);
  assert.equal(harness.runtime.state.status, 'offline');
  firstAttempt.reject(apiFailure('network-failure', {
    retryable: true,
    outcomeUnknown: true,
  }));
  await settle();
  harness.setOnline(true);
  assert.equal(harness.runtime.state.status, 'canonical-preparing');
  await harness.clock.tick(5);
  await preparation;

  assert.equal(harness.runtime.state.status, 'canonical-submitting');
  assert.equal(api.calls.prepareCanonicalAction.length, 2);
  assert.deepEqual(
    api.calls.prepareCanonicalAction[0].request,
    api.calls.prepareCanonicalAction[1].request,
  );
});

test('reconnect restores an in-flight canonical outcome state without starting ordinary preservation', async () => {
  const pendingOutcome = deferred();
  const api = createApi({
    getCanonicalActionOutcome: () => pendingOutcome.promise,
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');
  const query = harness.runtime.queryCanonicalActionOutcome(prepared.operationId);
  await settle();

  harness.setOnline(false);
  assert.equal(harness.runtime.state.status, 'offline');
  harness.setOnline(true);
  assert.equal(harness.runtime.state.status, 'canonical-outcome-pending');
  assert.equal(api.calls.preserve.length, 0);

  pendingOutcome.resolve(successfulOutcome);
  const outcome = await query;
  assert.equal(harness.runtime.reconcileCanonicalAction(outcome), true);
  assert.equal(harness.runtime.state.status, 'clean');
});

test('unresolved recovery blocks canonical preparation and emits metadata-only focus request', async () => {
  const api = createApi({ detect: async () => candidate });
  const harness = createRuntime({ api, detectOnStart: true });
  harness.runtime.start();
  await settle();

  await assert.rejects(
    harness.runtime.prepareCanonicalAction('save-exit'),
    (error) => error.code === 'recovery_resolution_required',
  );
  assert.equal(api.calls.prepareCanonicalAction.length, 0);
  const focus = harness.eventTarget.events.at(-1);
  assert.equal(focus.type, 'joomla:autosave-recoveryfocus');
  assert.equal(JSON.stringify(focus).includes('Recovered'), false);
});

test('successful canonical reconciliation becomes clean when no later edits exist', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');
  const outcome = await harness.runtime.queryCanonicalActionOutcome(prepared.operationId);

  assert.equal(harness.runtime.reconcileCanonicalAction(outcome), true);
  assert.equal(harness.runtime.state.status, 'clean');
  assert.equal(harness.runtime.state.dirty, false);
  assert.equal(harness.runtime.state.baseRevision, 'base-2');
  assert.equal(harness.runtime.state.canonicalAction, null);
});

test('canonical reconciliation is idempotent and rejects stale or malformed completions before mutation', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');
  const before = harness.runtime.state;

  assert.equal(harness.runtime.reconcileCanonicalAction({
    ...successfulOutcome,
    operation_id: 'stale-operation',
  }), false);
  assert.equal(harness.runtime.reconcileCanonicalAction({
    ...successfulOutcome,
    outcome: 'invented',
  }), false);
  assert.equal(harness.runtime.reconcileCanonicalAction({
    ...successfulOutcome,
    final_base_revision: '',
  }), false);
  assert.deepEqual(harness.runtime.state.canonicalAction, before.canonicalAction);

  assert.equal(harness.runtime.reconcileCanonicalAction(successfulOutcome), true);
  assert.equal(harness.runtime.reconcileCanonicalAction(successfulOutcome), false);
  assert.equal(harness.api.calls.initialize.length, 1);
  assert.equal(harness.api.calls.preserve.length, 0);
  assert.equal(prepared.operationId, 'operation');
});

test('edits made after Apply preparation create and preserve a successor generation after success', async () => {
  let generation = 0;
  const api = createApi({
    initialize: async () => {
      generation += 1;

      return {
        ...identity,
        continuation_id: `continuation-${generation}`,
        generation_id: `generation-${generation}`,
        base_revision: generation === 1 ? 'base-1' : 'base-2',
      };
    },
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');
  harness.adapter.value = { title: 'Later edit' };
  harness.adapter.edit();
  const outcome = await harness.runtime.queryCanonicalActionOutcome(prepared.operationId);
  harness.runtime.reconcileCanonicalAction(outcome);
  await settle();

  assert.equal(api.calls.initialize.length, 2);
  assert.equal(api.calls.preserve.length, 1);
  assert.equal(api.calls.prepareCanonicalAction[0].request.generation_id, 'generation-1');
  assert.equal(api.calls.preserve[0].request.generation_id, 'generation-2');
  assert.deepEqual(api.calls.preserve[0].request.payload, { title: 'Later edit' });
  assert.equal(harness.runtime.state.baseRevision, 'base-2');
  assert.equal(harness.runtime.state.status, 'preserved');
});

test('definitive canonical failure keeps work dirty and preserves it into a new generation', async () => {
  let generation = 0;
  const api = createApi({
    initialize: async (request) => {
      generation += 1;

      return {
        ...identity,
        continuation_id: `continuation-${generation}`,
        generation_id: `generation-${generation}`,
        base_revision: 'base-1',
      };
    },
    getCanonicalActionOutcome: async () => ({
      ...successfulOutcome,
      outcome: 'failed',
      final_target_id: null,
      final_base_revision: null,
      failure_code: 'canonical_save_failed',
      completed_at: '2026-08-05T00:00:01Z',
    }),
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');
  const outcome = await harness.runtime.queryCanonicalActionOutcome(prepared.operationId);
  harness.runtime.reconcileCanonicalAction(outcome);
  await settle();

  assert.equal(api.calls.initialize.length, 2);
  assert.equal(api.calls.preserve.length, 1);
  assert.equal(api.calls.prepareCanonicalAction[0].request.generation_id, 'generation-1');
  assert.equal(api.calls.preserve[0].request.generation_id, 'generation-2');
  assert.equal(harness.runtime.state.baseRevision, 'base-1');
  assert.equal(harness.runtime.state.status, 'preserved');
});

test('unknown canonical outcome remains paused and never claims success', async () => {
  const api = createApi({
    getCanonicalActionOutcome: async () => ({
      ...successfulOutcome,
      outcome: 'unknown',
      final_target_id: null,
      final_base_revision: null,
      completed_at: '2026-08-05T00:00:01Z',
    }),
  });
  const harness = createRuntime({ api });
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');
  const outcome = await harness.runtime.queryCanonicalActionOutcome(prepared.operationId);

  assert.equal(harness.runtime.reconcileCanonicalAction(outcome), false);
  assert.equal(harness.runtime.state.status, 'canonical-outcome-unknown');
  assert.equal(harness.runtime.state.canonicalAction.outcome, 'unknown');
  assert.equal(api.calls.preserve.length, 0);
});

test('exhausted outcome confirmation becomes explicit uncertainty without claiming completion', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  const prepared = await harness.runtime.prepareCanonicalAction('apply');

  assert.equal(
    harness.runtime.markCanonicalActionOutcomeUnconfirmed(
      prepared.operationId,
      apiFailure('network_failure', { retryable: true }),
    ),
    true,
  );
  assert.equal(harness.runtime.state.status, 'canonical-outcome-unknown');
  assert.equal(harness.runtime.state.canonicalAction.outcome, 'unknown');
  assert.equal(harness.runtime.state.error.code, 'network_failure');
  assert.equal(harness.runtime.reconcileCanonicalAction({
    ...successfulOutcome,
    operation_id: 'another-operation',
  }), false);
  await assert.rejects(
    harness.runtime.prepareCanonicalAction('apply'),
    (error) => error.code === 'canonical_action_unavailable',
  );
});

test('Cancel retains a preserved draft for recovery on a later visit', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  harness.adapter.edit();
  await harness.clock.tick(10);

  assert.equal(await harness.runtime.prepareForCancel(), true);
  assert.equal(harness.api.calls.discard.length, 0);
  assert.equal(harness.runtime.state.dirty, false);
  assert.equal(harness.runtime.state.status, 'preserved');
});

test('Cancel leaves a locally preserved draft detectable by the next runtime', async () => {
  let persisted = false;
  const api = createApi({
    preserve: async () => {
      persisted = true;

      return { status: 'accepted' };
    },
    detect: async () => (persisted ? candidate : null),
    discard: async () => {
      persisted = false;

      return { status: 'discarded' };
    },
  });
  const first = createRuntime({ api });
  first.runtime.start();
  first.adapter.edit();
  await first.clock.tick(10);

  assert.equal(await first.runtime.prepareForCancel(), true);
  assert.equal(api.calls.discard.length, 0);
  first.runtime.destroy();

  const second = createRuntime({ api, detectOnStart: true });
  second.runtime.start();
  await settle();

  assert.equal(second.runtime.state.status, 'recovery-required');
  assert.deepEqual(second.runtime.state.recoveryCandidate, {
    context: candidate.context,
    targetId: candidate.target_id,
    classification: candidate.classification,
    clientRevision: candidate.client_revision,
    schemaVersion: candidate.payload_schema_version,
    updatedAt: candidate.updated_at,
    expiresAt: candidate.expires_at,
    localEdits: false,
  });
  second.runtime.destroy();
});

test('Cancel leaves an unresolved detected draft durable for a later runtime', async () => {
  const api = createApi({ detect: async () => candidate });
  const first = createRuntime({ api, detectOnStart: true });
  first.runtime.start();
  await settle();

  assert.equal(first.runtime.state.status, 'recovery-required');
  const detectedCandidate = first.runtime.state.recoveryCandidate;
  assert.equal(await first.runtime.prepareForCancel(), true);
  assert.equal(api.calls.discard.length, 0);
  assert.deepEqual(first.runtime.state.recoveryCandidate, detectedCandidate);
  first.runtime.destroy();

  const second = createRuntime({ api, detectOnStart: true });
  second.runtime.start();
  await settle();

  assert.equal(second.runtime.state.status, 'recovery-required');
  assert.deepEqual(second.runtime.state.recoveryCandidate, detectedCandidate);
  assert.equal(api.calls.discard.length, 0);
});

test('state events contain metadata only and no source introduces forbidden coupling or HTML sinks', async () => {
  const harness = createRuntime();
  harness.runtime.start();
  harness.adapter.value = { articletext: '<p>private body</p>' };
  harness.adapter.edit();
  await harness.clock.tick(10);

  const serializedEvents = JSON.stringify(harness.eventTarget.events);
  assert.equal(serializedEvents.includes('private body'), false);
  assert.ok(harness.eventTarget.events.every((event) => event.type === AUTOSAVE_STATE_EVENT));

  const sourcePaths = [
    'media_source/com_autosave/src/api-client.es6.js',
    'media_source/com_autosave/src/adapter.es6.js',
    'media_source/com_autosave/js/runtime.es6.js',
  ];
  const sources = (await Promise.all(sourcePaths.map((path) => readFile(path, 'utf8')))).join('\n');
  const forbidden = [
    'innerHTML',
    'outerHTML',
    'insertAdjacentHTML',
    'DOMParser',
    'createContextualFragment',
    'setHTMLUnsafe',
    'sendBeacon',
    'com_content',
    'TinyMCE',
    'tinymce',
    'adminForm',
    'Joomla.submitform',
    'submitbutton',
    '/administrator/index.php',
    'console.log',
  ];

  forbidden.forEach((value) => assert.equal(sources.includes(value), false, value));
  assert.equal(/\beval\s*\(/.test(sources), false);
  assert.equal(/setTimeout\s*\(\s*['"]/.test(sources), false);
});
