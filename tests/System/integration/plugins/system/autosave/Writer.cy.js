/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// Import in the application window so the installed module uses its real browser globals.
let DraftRuntime;
let creationRecovery;
let application;
let runtimes;
let clock;
let fetchStub;
beforeEach(() => {
  runtimes = [];
  cy.visit('/administrator/index.php?option=com_login');
  cy.window().then(async (win) => {
    application = win;
    // Native import uses the application URL; no test import map or source loader is needed.

    const module = await win.Function('url', 'return import(url)')(new URL('media/com_autosave/js/drafts.js', win.Joomla.getOptions('system.paths').rootFull).href);
    DraftRuntime = module.default;
    creationRecovery = module.creationRecovery;
  });
});
afterEach(() => {
  runtimes.forEach((runtime) => runtime.destroy());
  if (clock) clock.restore();
  if (fetchStub) fetchStub.restore();
  clock = null;
  fetchStub = null;
});
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {
    promise,
    resolve,
    reject,
  };
};
const response = (data) => ({
  ok: true,
  json: async () => ({
    success: true,
    data,
  }),
});
const acknowledgement = (revision, conflict = false) => response({
  client_revision: revision,
  updated_at: '2026-10-11T00:00:00Z',
  conflict,
});
const setup = (options = {}) => {
  const runtime = new DraftRuntime({
    binding: {
      draftId: 'a'.repeat(32),
      version: 1,
      endpoint: 'http://localhost/administrator/index.php?option=com_ajax&plugin=autosave&group=system&format=json',
    },
    capture: () => ({
      title: '',
    }),
    fetcher: async (url, request) => acknowledgement(JSON.parse(request.body).client_revision),
    csrfToken: () => 'native-token',
    debounce: 10000,
    ...options,
  });
  runtimes.push(runtime);
  return runtime;
};
it('render binding starts with no initialization or canonical prepare request', () => {
  let requests = 0;
  let captures = 0;
  setup({
    capture: () => {
      captures += 1;
      return {
        title: '',
      };
    },
    fetcher: () => {
      requests += 1;
    },
  });
  assert.strictEqual(requests, 0);
  assert.strictEqual(captures, 0);
});
it('observation is cheap and capture occurs once for a coalesced draft', () => cy.then(async () => {
  let captures = 0;
  const runtime = setup({
    capture: () => {
      captures += 1;
      return {
        title: '',
      };
    },
  });
  for (let index = 0; index < 100; index += 1) runtime.changed();
  assert.strictEqual(captures, 0);
  await runtime.flush();
  assert.strictEqual(captures, 1);
  assert.strictEqual(runtime.state, 'preserved');
  assert.strictEqual(runtime.revision, 100);
}));
it('only one preserve is outstanding and later edits coalesce after it', () => cy.then(async () => {
  const first = deferred();
  const bodies = [];
  let value = 'first';
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
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
  assert.strictEqual(runtime.flush(), pending);
  assert.strictEqual(bodies.length, 1);
  first.resolve(acknowledgement(1));
  await pending;
  await runtime.flush();
  assert.strictEqual(bodies.length, 2);
  assert.strictEqual(bodies[1].client_revision, 3);
  assert.deepEqual(bodies[1].payload, {
    title: 'later',
  });
  assert.strictEqual(runtime.state, 'preserved');
}));
it('a response is required before a snapshot is described as preserved', () => cy.then(async () => {
  const waiting = deferred();
  const runtime = setup({
    fetcher: () => waiting.promise,
  });
  runtime.changed();
  const request = runtime.flush();
  assert.strictEqual(runtime.state, 'preserving');
  assert.strictEqual(runtime.lastPreserved, null);
  waiting.resolve(acknowledgement(1));
  await request;
  assert.strictEqual(runtime.lastPreserved, '2026-10-11T00:00:00Z');
}));
it('timeout includes response body and does not leave preservation stuck', () => cy.then(async () => {
  const runtime = setup({
    timeout: 20,
    fetcher: async () => ({
      ok: true,
      json: () => new Promise(() => {}),
    }),
  });
  runtime.changed();
  assert.strictEqual(await runtime.flush(), false);
  assert.strictEqual(runtime.state, 'offline');
  assert.strictEqual(runtime.inFlight, null);
  assert.strictEqual(runtime.requests.size, 0);
  assert.strictEqual(runtime.lastPreserved, null);
}));
for (const invalid of [null, {
  client_revision: 1,
}, {
  client_revision: 1,
  updated_at: 'invalid',
  conflict: false,
}, {
  client_revision: 1,
  updated_at: 42,
  conflict: false,
}, {
  client_revision: 1,
  updated_at: '2026-10-11T00:00:00Z',
}, {
  client_revision: 1,
  updated_at: '2026-10-11T00:00:00Z',
  conflict: 'true',
}]) {
  it('malformed preservation acknowledgement stays uncertain: ' + JSON.stringify(invalid), () => cy.then(async () => {
    const runtime = setup({
      fetcher: async () => response(invalid),
    });
    runtime.changed();
    assert.strictEqual(await runtime.flush(), false);
    assert.strictEqual(runtime.state, 'offline');
    assert.strictEqual(runtime.lastPreserved, null);
    assert.strictEqual(runtime.blocked, true);
    assert.strictEqual(runtime.lastAttempt.acknowledged, false);
    assert.deepEqual(runtime.failed.payload, {
      title: '',
    });
    assert.strictEqual(typeof runtime.submitted(), 'number', 'Native submission remains synchronous.');
  }));
}
it('a malformed acknowledgement retains the previous protection time and retries its exact snapshot', () => cy.then(async () => {
  const bodies = [];
  let value = 'first';
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
    fetcher: async (url, request) => {
      bodies.push(request.body);
      const revision = JSON.parse(request.body).client_revision;
      return bodies.length === 2
        ? response({
            client_revision: revision,
          })
        : acknowledgement(revision);
    },
  });
  runtime.changed();
  await runtime.flush();
  const previous = runtime.lastPreserved;
  value = 'uncertain';
  runtime.changed();
  assert.strictEqual(await runtime.flush(), false);
  assert.strictEqual(runtime.lastPreserved, previous);
  value = 'later';
  runtime.changed();
  assert.strictEqual(await runtime.retry(), true);
  assert.strictEqual(bodies[1], bodies[2]);
  await runtime.flush();
  assert.strictEqual(JSON.parse(bodies[3]).payload.title, 'later');
}));
it('transport retry keeps the exact sequence and snapshot despite newer edits', () => cy.then(async () => {
  const bodies = [];
  let value = 'uncertain snapshot';
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
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
  assert.strictEqual(bodies[0], bodies[1]);
  await runtime.flush();
  assert.strictEqual(JSON.parse(bodies[2]).payload.title, 'newer edits');
  assert.strictEqual(runtime.state, 'preserved');
}));
it('native submission is synchronous even while the draft endpoint is unavailable', () => cy.then(async () => {
  const waiting = deferred();
  const runtime = setup({
    fetcher: () => waiting.promise,
  });
  runtime.changed();
  const submitted = runtime.submitted();
  assert.strictEqual(submitted, 2);
  runtime.changed();
  assert.strictEqual(submitted, 2);
  assert.strictEqual(runtime.revision, 3);
  waiting.resolve(acknowledgement(2));
  await runtime.inFlight;
}));
it('retirement of an older request does not suppress a later dirty sequence', () => cy.then(async () => {
  const waiting = deferred();
  let requests = 0;
  const runtime = setup({
    fetcher: async (url, request) => {
      requests += 1;
      return requests === 1 ? waiting.promise : acknowledgement(JSON.parse(request.body).client_revision);
    },
  });
  runtime.changed();
  const first = runtime.flush();
  runtime.changed();
  waiting.resolve({
    ok: false,
    json: async () => ({
      success: false,
      data: {
        code: 'draft_retired',
      },
    }),
  });
  await first;
  await runtime.flush();
  assert.strictEqual(requests, 2);
  assert.strictEqual(runtime.state, 'preserved');
}));
it('discard makes a delayed preserve acknowledgement unable to change the UI', () => cy.then(async () => {
  const waiting = deferred();
  const runtime = setup({
    fetcher: async (url) => url.includes('draft.discard')
      ? response({
          discarded: true,
        })
      : waiting.promise,
  });
  runtime.changed();
  const preserve = runtime.flush();
  await runtime.discard('a'.repeat(32));
  waiting.resolve(acknowledgement(1));
  await preserve;
  assert.strictEqual(runtime.state, 'discarded');
  assert.strictEqual(runtime.lastPreserved, null);
  runtime.changed();
  assert.strictEqual(await runtime.flush(), false);
}));
it('disposed native forms ignore late responses and unsubscribe request ownership', () => cy.then(async () => {
  const waiting = deferred();
  const states = [];
  const runtime = setup({
    fetcher: () => waiting.promise,
    onState: (state) => states.push(state.state),
  });
  runtime.changed();
  const request = runtime.flush();
  runtime.destroy();
  const count = states.length;
  waiting.resolve(acknowledgement(1));
  await request;
  assert.strictEqual(states.length, count);
  assert.strictEqual(runtime.lastPreserved, null);
  assert.strictEqual(runtime.requests.size, 0);
}));
it('confirmed preservation with an uncertain canonical baseline discloses conflict', () => cy.then(async () => {
  const runtime = setup({
    fetcher: async () => acknowledgement(1, true),
  });
  runtime.changed();
  await runtime.flush();
  assert.strictEqual(runtime.state, 'conflict');
  assert.strictEqual(runtime.lastPreserved, '2026-10-11T00:00:00Z');
  runtime.changed();
  assert.strictEqual(await runtime.flush(), false);
}));
it('unsupported controls leave native submission metadata optional', () => {
  const runtime = setup({
    capture: () => {
      throw new Error('Unsupported provider');
    },
  });
  assert.strictEqual(runtime.submitted(), null);
  assert.strictEqual(runtime.state, 'unavailable');
  assert.strictEqual(runtime.lastPreserved, null);
});
it('native submission captures its protected values once and freezes that projection', () => cy.then(async () => {
  let captures = 0;
  let sent;
  const runtime = setup({
    capture: () => {
      captures += 1;
      return {
        title: 'submitted',
      };
    },
    fetcher: async (url, request) => {
      sent = JSON.parse(request.body);
      return acknowledgement(sent.client_revision);
    },
  });
  assert.strictEqual(runtime.submitted(), 1);
  await runtime.inFlight;
  assert.strictEqual(captures, 1);
  assert.deepEqual(sent.payload, {
    title: 'submitted',
  });
}));
it('detection shows a busy state and becomes ready only after a valid acknowledgement', () => cy.then(async () => {
  const pending = deferred();
  const runtime = setup({
    fetcher: () => pending.promise,
  });
  const detection = runtime.candidates();
  assert.strictEqual(runtime.state, 'detecting');
  pending.resolve(response({
    candidates: [],
    conflict: false,
  }));
  await detection;
  assert.strictEqual(runtime.state, 'clean');
  assert.strictEqual(runtime.lastPreserved, null);
}));
it('failed detection remains unavailable for protection and can retry without native saving', () => cy.then(async () => {
  let attempts = 0;
  const runtime = setup({
    fetcher: async () => {
      attempts += 1;
      if (attempts === 1) throw new TypeError('Network unavailable');
      return response({
        candidates: [],
        conflict: false,
      });
    },
  });
  await runtime.candidates().then(() => assert.fail('Expected request rejection'), (error) => {
    assert.propertyVal(error, 'code', 'transport_failure');
  });
  assert.strictEqual(runtime.state, 'offline');
  await runtime.candidates();
  assert.strictEqual(runtime.state, 'clean');
  assert.strictEqual(runtime.revision, 0);
}));
it('malformed detection data never claims readiness', () => cy.then(async () => {
  const runtime = setup({
    fetcher: async () => response({
      candidates: [{
        draft_id: 'unknown',
        updated_at: 'invalid',
      }],
      conflict: false,
    }),
  });
  await runtime.candidates().then(() => assert.fail('Expected request rejection'), (error) => {
    assert.propertyVal(error, 'code', 'service_unavailable');
  });
  assert.strictEqual(runtime.state, 'offline');
}));
it('late detection failure cannot replace an acknowledged preserve status', () => cy.then(async () => {
  const pending = deferred();
  const runtime = setup({
    fetcher: async (url, request) => new URL(url).searchParams.get('task') === 'draft.detect' ? pending.promise : acknowledgement(JSON.parse(request.body).client_revision),
  });
  const detection = runtime.candidates();
  runtime.changed();
  await runtime.flush();
  pending.reject(new TypeError('Old detection failed'));
  await detection.then(() => assert.fail('Expected request rejection'), (error) => {
    assert.propertyVal(error, 'code', 'transport_failure');
  });
  assert.strictEqual(runtime.state, 'preserved');
  assert.strictEqual(runtime.lastPreserved, '2026-10-11T00:00:00Z');
}));
it('late detection conflict cannot replace a newer validated preservation', () => cy.then(async () => {
  const pending = deferred();
  const runtime = setup({
    fetcher: async (url, request) => new URL(url).searchParams.get('task') === 'draft.detect' ? pending.promise : acknowledgement(JSON.parse(request.body).client_revision),
  });
  const detection = runtime.candidates();
  runtime.changed();
  await runtime.flush();
  pending.resolve(response({
    candidates: [],
    conflict: true,
  }));
  await detection;
  assert.strictEqual(runtime.state, 'preserved');
  assert.strictEqual(runtime.blocked, false);
}));
it('discard invalidates an earlier detection completion', () => cy.then(async () => {
  const pending = deferred();
  const runtime = setup({
    fetcher: async (url) => new URL(url).searchParams.get('task') === 'draft.detect'
      ? pending.promise
      : response({
          discarded: true,
        }),
  });
  const detection = runtime.candidates();
  await runtime.discard(runtime.binding.draftId);
  pending.resolve(response({
    candidates: [],
    conflict: false,
  }));
  await detection.then(() => assert.fail('Expected request rejection'), (error) => {
    assert.propertyVal(error, 'name', 'AbortError');
  });
  assert.strictEqual(runtime.state, 'discarded');
}));
it('current detection conflict pauses capture instead of claiming ready', () => cy.then(async () => {
  const runtime = setup({
    fetcher: async () => response({
      candidates: [],
      conflict: true,
    }),
  });
  await runtime.candidates();
  assert.strictEqual(runtime.state, 'conflict');
  assert.strictEqual(runtime.blocked, true);
}));
it('default browser transport keeps the Window receiver for detection and preservation', () => cy.then(async () => {
  const sent = [];
  fetchStub = Cypress.sinon.stub(application, 'fetch').callsFake(function browserFetch(url, options) {
    assert.strictEqual(this, application, 'Window.fetch requires its native receiver');
    const query = new URL(url).searchParams;
    const operation = query.get('task');
    assert.strictEqual(query.get('option'), 'com_ajax');
    assert.strictEqual(query.get('plugin'), 'autosave');
    assert.strictEqual(query.get('group'), 'system');
    assert.strictEqual(query.get('format'), 'json');
    assert.strictEqual(options.method, 'POST');
    assert.strictEqual(options.credentials, 'same-origin');
    sent.push(operation);
    assert.strictEqual(options.headers['X-CSRF-Token'], 'native-token');
    return Promise.resolve(operation === 'draft.detect'
      ? response({
          candidates: [],
          conflict: false,
        })
      : acknowledgement(JSON.parse(options.body).client_revision));
  });
  const runtime = setup({
    fetcher: undefined,
  });
  assert.deepEqual(await runtime.candidates(), {
    candidates: [],
    conflict: false,
  });
  assert.strictEqual(runtime.state, 'clean');
  runtime.changed();
  assert.strictEqual(await runtime.flush(), true);
  assert.strictEqual(runtime.state, 'preserved');
  assert.deepEqual(sent, ['draft.detect', 'draft.preserve']);
}));
it('explicit discovery paging forwards an opaque cursor without writing a draft', () => cy.then(async () => {
  const cursor = 'b'.repeat(32);
  let sent;
  const runtime = setup({
    fetcher: async (url, request) => {
      assert.strictEqual(new URL(url).searchParams.get('task'), 'draft.detect');
      sent = JSON.parse(request.body);
      return response({
        candidates: [],
        next: null,
        conflict: false,
      });
    },
  });
  const result = await runtime.candidates(cursor);
  assert.deepEqual(sent, {
    draft_id: 'a'.repeat(32),
    cursor,
  });
  assert.strictEqual(result.next, null);
  assert.strictEqual(runtime.revision, 0);
  assert.strictEqual(runtime.pending, false);
}));
it('invalid next-page metadata never claims recovery readiness', () => cy.then(async () => {
  const runtime = setup({
    fetcher: async () => response({
      candidates: [],
      next: 'not-a-draft-id',
      conflict: false,
    }),
  });
  await runtime.candidates().then(() => assert.fail('Expected request rejection'), (error) => {
    assert.propertyVal(error, 'code', 'service_unavailable');
  });
  assert.strictEqual(runtime.state, 'offline');
}));
it('an unchanged initial creation capture never becomes a recovery payload', () => cy.then(async () => {
  let requests = 0;
  const runtime = setup({
    capture: () => ({
      title: '',
      articletext: '',
    }),
    baseline: () => ({
      title: '',
      articletext: '',
    }),
    fetcher: async () => {
      requests += 1;
      return acknowledgement(1);
    },
  });
  runtime.changed();
  assert.strictEqual(await runtime.flush(), false);
  assert.strictEqual(requests, 0);
  assert.strictEqual(runtime.pending, false);
  assert.strictEqual(runtime.state, 'clean');
  assert.strictEqual(runtime.lastPreserved, null);
}));
it('creation capture after genuine edits and returning to defaults remains recoverable', () => cy.then(async () => {
  let value = 'unfinished';
  const snapshots = [];
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
    baseline: () => ({
      title: '',
    }),
    fetcher: async (url, request) => {
      const snapshot = JSON.parse(request.body);
      snapshots.push(snapshot);
      return acknowledgement(snapshot.client_revision);
    },
  });
  runtime.changed();
  await runtime.flush();
  value = '';
  runtime.changed();
  await runtime.flush();
  assert.deepEqual(snapshots.map((snapshot) => snapshot.payload), [{
    title: 'unfinished',
  }, {
    title: '',
  }]);
}));
const creationBinding = (draftId = 'a'.repeat(32)) => ({
  creating: true,
  context: 'com_content.article',
  draftId,
});
const historyFixture = (state = null) => ({
  state: application.JSON.parse(JSON.stringify(state)),
  replaceState(next, title) {
    assert.strictEqual(title, '');
    this.state = application.JSON.parse(JSON.stringify(next));
  },
});
it('fresh New ignores old creation references and preserves native history state', () => {
  const history = historyFixture({
    native: {
      scroll: 20,
    },
    joomlaAutosaveCreation: {
      context: 'com_content.article',
      draftId: 'b'.repeat(32),
      acknowledgedId: 'b'.repeat(32),
    },
  });
  const session = creationRecovery(creationBinding(), history, 'navigate');
  assert.deepEqual(session.sources, {});
  assert.deepEqual(history.state, {
    native: {
      scroll: 20,
    },
  });
  assert.strictEqual(history.state.joomlaAutosaveCreation, undefined);
});
it('creation reload automatically identifies its pending and acknowledged instances', () => {
  const history = historyFixture();
  const first = creationRecovery(creationBinding(), history, 'navigate');
  assert.strictEqual(history.state, null);
  assert.strictEqual(first.attempted(), true);
  assert.strictEqual(first.acknowledged(), true);
  const reload = creationRecovery(creationBinding('b'.repeat(32)), history, 'reload');
  assert.deepEqual(reload.sources, {
    creation_id: 'a'.repeat(32),
    acknowledged_id: 'a'.repeat(32),
  });
  const anotherReload = creationRecovery(creationBinding('c'.repeat(32)), history, 'reload');
  assert.deepEqual(anotherReload.sources, reload.sources);
  assert.strictEqual(history.state.joomlaAutosaveCreation.draftId, 'a'.repeat(32));
});
it('an uncertain later write retains the last acknowledged creation reference', () => {
  const history = historyFixture();
  const first = creationRecovery(creationBinding(), history, 'navigate');
  first.attempted();
  first.acknowledged();
  const reload = creationRecovery(creationBinding('b'.repeat(32)), history, 'reload');
  reload.attempted();
  const again = creationRecovery(creationBinding('c'.repeat(32)), history, 'reload');
  assert.deepEqual(again.sources, {
    creation_id: 'b'.repeat(32),
    acknowledged_id: 'a'.repeat(32),
  });
  reload.acknowledged();
  assert.deepEqual(creationRecovery(creationBinding('d'.repeat(32)), history, 'reload').sources, {
    creation_id: 'b'.repeat(32),
    acknowledged_id: 'b'.repeat(32),
  });
});
it('creation references are tab-entry scoped and never attach to other contexts', () => {
  const history = historyFixture();
  const first = creationRecovery(creationBinding(), history, 'navigate');
  first.attempted();
  first.acknowledged();
  assert.deepEqual(creationRecovery(creationBinding('b'.repeat(32)), historyFixture(), 'reload').sources, {});
  assert.deepEqual(creationRecovery({
    ...creationBinding(),
    context: 'another.context',
  }, history, 'reload').sources, {});
  assert.strictEqual(creationRecovery({
    creating: false,
  }, history, 'reload'), null);
});
it('invalid references and unavailable history degrade without throwing or storing content', () => {
  const history = historyFixture({
    native: 'preserved',
    joomlaAutosaveCreation: {
      context: 'com_content.article',
      draftId: '<invalid>',
      acknowledgedId: 'bad',
    },
  });
  assert.deepEqual(creationRecovery(creationBinding(), history, 'reload').sources, {});
  const broken = {
    state: null,
    replaceState() {
      throw new DOMException('History denied');
    },
  };
  assert.strictEqual(creationRecovery(creationBinding(), broken, 'navigate').attempted(), false);
  assert.strictEqual(creationRecovery(creationBinding(), historyFixture('native scalar'), 'reload'), false);
  const session = creationRecovery(creationBinding(), history, 'reload');
  session.attempted();
  session.acknowledged();
  assert.deepEqual(Object.keys(history.state.joomlaAutosaveCreation).sort(), ['acknowledgedId', 'context', 'draftId']);
  assert.strictEqual(history.state.native, 'preserved');
});
it('unchanged submit-time editor notifications cannot create a revision beyond native retirement', () => cy.then(async () => {
  const request = deferred();
  const bodies = [];
  const runtime = setup({
    capture: () => ({
      title: 'submitted',
      articletext: 'body',
    }),
    fetcher: async (url, options) => {
      bodies.push(JSON.parse(options.body));
      return request.promise;
    },
  });
  const submitted = runtime.submitted();
  const pending = runtime.inFlight;
  runtime.changed();
  request.resolve(acknowledgement(submitted));
  await pending;
  assert.strictEqual(await runtime.flush(), false);
  assert.strictEqual(bodies.length, 1);
  assert.strictEqual(bodies[0].client_revision, submitted);
  assert.strictEqual(runtime.pending, false);
}));
it('a submitted snapshot queued behind an earlier write keeps the exact native sequence', () => cy.then(async () => {
  const earlier = deferred();
  const bodies = [];
  let value = 'earlier';
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      return bodies.length === 1 ? earlier.promise : acknowledgement(body.client_revision);
    },
  });
  runtime.changed();
  const pending = runtime.flush();
  value = 'submitted';
  const submitted = runtime.submitted();
  runtime.changed();
  earlier.resolve(acknowledgement(1));
  await pending;
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.client_revision), [1, submitted]);
  assert.strictEqual(bodies[1].payload.title, 'submitted');
  assert.strictEqual(runtime.pending, false);
}));
it('actual later edits and later undo remain separately recoverable after submission', () => cy.then(async () => {
  const bodies = [];
  let value = 'submitted';
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      return acknowledgement(body.client_revision);
    },
  });
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
}));
it('failed native-save draft transport still retries its exact submitted snapshot', () => cy.then(async () => {
  const bodies = [];
  const runtime = setup({
    capture: () => ({
      title: 'submitted',
    }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      if (bodies.length === 1) throw new TypeError('Transport unavailable');
      return acknowledgement(body.client_revision);
    },
  });
  const submitted = runtime.submitted();
  await runtime.inFlight;
  runtime.changed();
  assert.strictEqual(await runtime.retry(), true);
  await runtime.flush();
  assert.deepEqual(bodies.map((body) => body.client_revision), [submitted, submitted]);
  assert.strictEqual(runtime.pending, false);
}));
it('a late retired submission never claims draft acknowledgement or writes unchanged values again', () => cy.then(async () => {
  const request = deferred();
  const bodies = [];
  const runtime = setup({
    capture: () => ({
      title: 'submitted',
    }),
    fetcher: async (url, options) => {
      bodies.push(JSON.parse(options.body));
      return request.promise;
    },
  });
  runtime.submitted();
  const pending = runtime.inFlight;
  runtime.changed();
  request.resolve({
    ok: false,
    json: async () => ({
      success: false,
      data: {
        code: 'draft_retired',
      },
    }),
  });
  await pending;
  assert.strictEqual(await runtime.flush(), false);
  assert.strictEqual(bodies.length, 1);
  assert.strictEqual(runtime.pending, false);
  assert.strictEqual(runtime.state, 'clean');
  assert.strictEqual(runtime.lastPreserved, null);
}));
it('genuine edits while submission waits behind an earlier write retain a higher sequence', () => cy.then(async () => {
  const earlier = deferred();
  const bodies = [];
  let value = 'earlier';
  const runtime = setup({
    capture: () => ({
      title: value,
    }),
    fetcher: async (url, options) => {
      const body = JSON.parse(options.body);
      bodies.push(body);
      return bodies.length === 1 ? earlier.promise : acknowledgement(body.client_revision);
    },
  });
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
  assert.strictEqual(bodies[1].payload.title, 'real later edit');
  assert.strictEqual(runtime.state, 'preserved');
}));
it('unchanged native array selections do not manufacture a draft for fresh creation', () => cy.then(async () => {
  const runtime = setup({
    baseline: () => ({
      rules: ['1', '3'],
    }),
    capture: () => ({
      rules: ['1', '3'],
    }),
    fetcher: async () => {
      throw new Error('Untouched creation must not write');
    },
  });
  runtime.changed();
  assert.strictEqual(await runtime.flush(), false);
  assert.strictEqual(runtime.state, 'clean');
}));
it('unchanged array notifications during native Save keep the submitted retirement floor', () => cy.then(async () => {
  const bodies = [];
  let selected = ['1', '3'];
  const runtime = setup({
    capture: () => ({
      rules: [...selected],
    }),
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
  assert.strictEqual(bodies.length, 2);
  assert.deepEqual(bodies[1].payload.rules, ['1']);
}));
it('continuous edits reach the dirty deadline without an idle periodic write', () => cy.then(async () => {
  clock = Cypress.sinon.useFakeTimers({
    global: application,
    toFake: ['setTimeout', 'clearTimeout', 'Date'],
  });
  const bodies = [];
  const runtime = setup({
    debounce: 600,
    interval: 1000,
    fetcher: async (url, request) => {
      bodies.push(JSON.parse(request.body));
      return acknowledgement(bodies.at(-1).client_revision);
    },
  });
  clock.tick(3000);
  assert.strictEqual(bodies.length, 0);
  for (let index = 0; index < 10; index += 1) {
    runtime.changed();
    clock.tick(100);
  }
  await runtime.inFlight;
  assert.strictEqual(bodies.length, 1);
  clock.tick(5000);
  assert.strictEqual(bodies.length, 1);
}));
const responseError = (code) => ({
  ok: false,
  json: async () => ({
    success: false,
    data: {
      code,
    },
  }),
});
for (const code of ['draft_expired', 'draft_not_found']) {
  it('binding failure retains local work without a resume request: ' + code, () => cy.then(async () => {
    clock = Cypress.sinon.useFakeTimers({
      global: application,
      toFake: ['setTimeout', 'clearTimeout', 'Date'],
    });
    const operations = [];
    let value = 'local work';
    const runtime = setup({
      capture: () => ({
        title: value,
      }),
      fetcher: async (url) => {
        operations.push(new URL(url).searchParams.get('task'));
        return responseError(code);
      },
    });
    runtime.changed();
    assert.strictEqual(await runtime.flush(), false);
    value = 'later local work';
    runtime.changed();
    clock.tick(60000);
    assert.deepEqual(operations, ['draft.preserve']);
    assert.strictEqual(value, 'later local work');
    assert.strictEqual(runtime.state, 'unavailable');
    assert.strictEqual(runtime.lastPreserved, null);
    assert.strictEqual(runtime.submitted() > 0, true);
  }));
}
it('an already absent recovery choice can be dismissed', () => cy.then(async () => {
  const runtime = setup({
    fetcher: async () => responseError('draft_not_found'),
  });
  await runtime.discard('b'.repeat(32));
  assert.notStrictEqual(runtime.state, 'discarded');
}));
for (const creating of [true, false]) {
  it('the shared writer refuses untouched new and existing native projections: creating=' + creating, () => cy.then(async () => {
    const runtime = setup({
      binding: {
        draftId: 'a'.repeat(32),
        version: 1,
        creating,
        endpoint: 'http://localhost/administrator/index.php?option=com_ajax&plugin=autosave&group=system&format=json',
      },
      capture: () => ({
        title: '',
        rules: ['1', '3'],
      }),
      fetcher: async () => {
        throw new Error('Untouched native form must not preserve');
      },
    });
    runtime.initialProjection = {
      title: '',
      rules: ['1', '3'],
    };
    runtime.changed();
    assert.strictEqual(await runtime.flush(), false);
    assert.strictEqual(runtime.state, 'clean');
    assert.strictEqual(runtime.lastPreserved, null);
  }));
}
