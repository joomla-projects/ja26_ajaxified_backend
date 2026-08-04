/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
  AutosaveApiClient,
  AutosaveApiError,
} from '../../../../media_source/com_autosave/js/runtime.es6.js';

const endpoints = {
  initialize: '/subdir/administrator/index.php?option=com_autosave&task=autosave.initialize&format=json',
  preserve: '/subdir/administrator/index.php?option=com_autosave&task=autosave.preserve&format=json',
  detect: '/subdir/administrator/index.php?option=com_autosave&task=autosave.detect&format=json',
  read: '/subdir/administrator/index.php?option=com_autosave&task=autosave.read&format=json',
  discard: '/subdir/administrator/index.php?option=com_autosave&task=autosave.discard&format=json',
};

const metadata = {
  continuation_id: 'continuation',
  generation_id: 'generation',
  context: 'com_example.record',
  target_id: '42',
  base_revision: 'base-1',
  current_base_revision: 'base-1',
  classification: 'current',
  client_revision: 1,
  payload_schema_version: 1,
  updated_at: '2026-08-02T00:00:00Z',
  expires_at: '2026-08-09T00:00:00Z',
};

const initializeRequest = {
  context: 'com_example.record',
  target_id: '42',
  initialization_key: 'init-key',
};
const preserveRequest = {
  continuation_id: 'continuation',
  generation_id: 'generation',
  client_revision: 1,
  payload_schema_version: 1,
  payload: { title: 'Draft' },
};
const identityRequest = {
  continuation_id: 'continuation',
  generation_id: 'generation',
};

const response = ({
  status = 200,
  body = { success: true, data: null },
  contentType = 'application/json; charset=utf-8',
  redirected = false,
  type = 'basic',
  retryAfter = null,
  jsonError = null,
} = {}) => ({
  ok: status >= 200 && status < 300,
  status,
  redirected,
  type,
  headers: {
    get(name) {
      if (name.toLowerCase() === 'content-type') {
        return contentType;
      }

      if (name.toLowerCase() === 'retry-after') {
        return retryAfter;
      }

      return null;
    },
  },
  async json() {
    if (jsonError) {
      throw jsonError;
    }

    return body;
  },
});

const createClient = (fetchImpl, overrides = {}) => new AutosaveApiClient({
  endpoints,
  csrf: 'csrf-token',
  fetchImpl,
  requestTimeout: 1000,
  ...overrides,
});

test('uses the literal PR 7 requests, injected endpoints, and transport options', async () => {
  const requests = [];
  const responses = {
    initialize: {
      continuation_id: 'continuation',
      generation_id: 'generation',
      context: 'com_example.record',
      target_id: '42',
      base_revision: 'base-1',
      payload_schema_version: 1,
    },
    preserve: { status: 'accepted' },
    detect: metadata,
    read: { ...metadata, created_at: '2026-08-01T00:00:00Z', payload: { title: 'Draft' } },
    discard: { status: 'discarded' },
  };
  let currentOperation;
  const client = createClient(async (url, options) => {
    requests.push({ url, options });

    return response({ body: { success: true, data: responses[currentOperation] } });
  });
  const operationRequests = {
    initialize: { context: 'com_example.record', target_id: '42', initialization_key: 'init-key' },
    preserve: {
      continuation_id: 'continuation',
      generation_id: 'generation',
      client_revision: 1,
      payload_schema_version: 1,
      payload: { title: 'Draft' },
    },
    detect: { context: 'com_example.record', target_id: '42' },
    read: { continuation_id: 'continuation', generation_id: 'generation' },
    discard: { continuation_id: 'continuation', generation_id: 'generation' },
  };

  for (const [operation, request] of Object.entries(operationRequests)) {
    currentOperation = operation;
    const data = await client[operation](request);
    const sent = requests.at(-1);

    assert.deepEqual(data, responses[operation]);
    assert.equal(sent.url, endpoints[operation]);
    assert.deepEqual(JSON.parse(sent.options.body), request);
    assert.equal(sent.options.method, 'POST');
    assert.equal(sent.options.credentials, 'same-origin');
    assert.equal(sent.options.cache, 'no-store');
    assert.equal(sent.options.headers.Accept, 'application/json');
    assert.equal(sent.options.headers['Content-Type'], 'application/json');
    assert.equal(sent.options.headers['X-CSRF-Token'], 'csrf-token');
    assert.ok(sent.options.signal instanceof AbortSignal);
    assert.equal('redirect' in sent.options, false);
  }
});

test('accepts a null detect result and idempotent mutation acknowledgements', async () => {
  const queue = [null, { status: 'idempotent' }, { status: 'idempotent' }];
  const client = createClient(async () => response({
    body: { success: true, data: queue.shift() },
  }));

  assert.equal(await client.detect({ context: 'com_example.record', target_id: '42' }), null);
  assert.deepEqual(await client.preserve(preserveRequest), { status: 'idempotent' });
  assert.deepEqual(await client.discard(identityRequest), { status: 'idempotent' });
});

test('rejects redirects, HTML, wrong media types, malformed JSON, and malformed success envelopes', async (t) => {
  const cases = [
    ['redirect', response({ redirected: true }), 'authentication-required'],
    ['opaque redirect', response({ type: 'opaqueredirect' }), 'authentication-required'],
    ['HTML login', response({ contentType: 'text/html' }), 'authentication-required'],
    ['wrong type', response({ contentType: 'text/plain' }), 'malformed-response'],
    ['malformed JSON', response({ jsonError: new SyntaxError('bad') }), 'malformed-response'],
    ['missing data', response({ body: { success: true } }), 'malformed-response'],
    ['wrong operation data', response({ body: { success: true, data: { status: 'unexpected' } } }), 'malformed-response'],
  ];

  for (const [name, result, classification] of cases) {
    await t.test(name, async () => {
      const client = createClient(async () => result);

      await assert.rejects(
        client.preserve({ ...preserveRequest, payload: { private: 'never exposed' } }),
        (error) => error instanceof AutosaveApiError
          && error.classification === classification
          && !error.message.includes('never exposed'),
      );
    });
  }
});

test('classifies every PR 7 error family without exposing server messages', async (t) => {
  const cases = [
    [401, 'authentication_required', 'authentication-required', false],
    [403, 'backend_access_denied', 'permission-denied', false],
    [403, 'invalid_csrf_token', 'csrf-failure', false],
    [403, 'forbidden', 'permission-denied', false],
    [422, 'invalid_target', 'validation-failure', false],
    [422, 'invalid_payload', 'payload-failure', false],
    [422, 'unsupported_schema_version', 'payload-failure', false],
    [404, 'draft_not_found', 'draft-not-found', false],
    [409, 'revision_conflict', 'conflict', false],
    [409, 'stale_client_revision', 'conflict', false],
    [409, 'draft_terminal', 'terminal-generation', false],
    [410, 'draft_expired', 'terminal-generation', false],
    [429, 'draft_limit_reached', 'rate-limited', true],
    [500, 'internal_error', 'temporary-server-failure', true],
  ];

  for (const [status, code, classification, retryable] of cases) {
    await t.test(code, async () => {
      const client = createClient(async () => response({
        status,
        body: {
          success: false,
          error: { code, message: 'Sensitive server detail' },
        },
        retryAfter: status === 429 ? '3' : null,
      }));

      await assert.rejects(client.preserve(preserveRequest), (error) => {
        assert.equal(error.code, code);
        assert.equal(error.classification, classification);
        assert.equal(error.retryable, retryable);
        assert.equal(error.outcomeUnknown, false);
        assert.equal(error.message.includes('Sensitive server detail'), false);

        if (status === 429) {
          assert.equal(error.retryAfter, 3000);
        }

        return true;
      });
    });
  }
});

test('classifies malformed error envelopes by HTTP status', async () => {
  const client = createClient(async () => response({
    status: 503,
    body: { unexpected: true },
  }));

  await assert.rejects(client.initialize(initializeRequest), (error) => {
    assert.equal(error.code, 'malformed_error_envelope');
    assert.equal(error.classification, 'malformed-response');
    assert.equal(error.retryable, true);
    assert.equal(error.outcomeUnknown, true);

    return true;
  });
});

test('classifies network failures as retryable outcome-unknown writes', async () => {
  const client = createClient(async () => {
    throw new TypeError('network down');
  });

  await assert.rejects(client.preserve(preserveRequest), (error) => {
    assert.equal(error.classification, 'network-failure');
    assert.equal(error.retryable, true);
    assert.equal(error.outcomeUnknown, true);

    return true;
  });
});

test('times out through AbortController and distinguishes owner aborts', async () => {
  const timers = {
    callback: null,
    setTimeout(callback) {
      this.callback = callback;

      return 1;
    },
    clearTimeout() {},
  };
  const fetchImpl = (url, { signal }) => new Promise((resolve, reject) => {
    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
  });
  const timeoutClient = createClient(fetchImpl, { timers });
  const timedRequest = timeoutClient.preserve(preserveRequest);
  timers.callback();

  await assert.rejects(timedRequest, (error) => {
    assert.equal(error.classification, 'request-timeout');
    assert.equal(error.retryable, true);
    assert.equal(error.outcomeUnknown, true);

    return true;
  });

  const owner = new AbortController();
  const abortedRequest = timeoutClient.preserve(preserveRequest, { signal: owner.signal });
  owner.abort();

  await assert.rejects(abortedRequest, (error) => {
    assert.equal(error.classification, 'request-aborted');
    assert.equal(error.retryable, false);

    return true;
  });
});

test('never writes requests or payloads to console', async () => {
  const methods = ['log', 'debug', 'info', 'warn', 'error'];
  const originals = new Map();
  let calls = 0;

  methods.forEach((method) => {
    originals.set(method, console[method]);
    console[method] = () => {
      calls += 1;
    };
  });

  try {
    const client = createClient(async () => response({
      body: { success: true, data: { status: 'accepted' } },
    }));
    await client.preserve({ ...preserveRequest, payload: { secret: 'not logged' } });
    assert.equal(calls, 0);
  } finally {
    methods.forEach((method) => {
      console[method] = originals.get(method);
    });
  }
});

test('rejects non-literal outgoing request schemas before transport', async () => {
  let fetchCalls = 0;
  const client = createClient(async () => {
    fetchCalls += 1;

    return response();
  });

  await assert.rejects(
    client.preserve({ ...preserveRequest, extra: true }),
    (error) => error.code === 'invalid_request_payload'
      && error.classification === 'validation-failure'
      && error.outcomeUnknown === false,
  );
  assert.equal(fetchCalls, 0);
});
