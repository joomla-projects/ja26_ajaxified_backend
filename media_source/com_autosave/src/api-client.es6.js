/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const OPERATIONS = [
  'initialize',
  'initializeCreate',
  'preserve',
  'detect',
  'read',
  'discard',
  'prepareCanonicalAction',
  'getCanonicalActionOutcome',
];
const REQUIRED_OPERATIONS = OPERATIONS.filter((operation) => operation !== 'initializeCreate');
const MUTATION_OPERATIONS = new Set([
  'initialize',
  'initializeCreate',
  'preserve',
  'discard',
  'prepareCanonicalAction',
]);

const ERROR_CLASSIFICATIONS = new Map([
  ['authentication_required', 'authentication-required'],
  ['backend_access_denied', 'permission-denied'],
  ['invalid_csrf_token', 'csrf-failure'],
  ['forbidden', 'permission-denied'],
  ['malformed_context', 'validation-failure'],
  ['unsupported_context', 'validation-failure'],
  ['invalid_target', 'validation-failure'],
  ['target_not_found', 'validation-failure'],
  ['invalid_request', 'validation-failure'],
  ['malformed_json', 'validation-failure'],
  ['unsupported_media_type', 'validation-failure'],
  ['request_too_large', 'payload-failure'],
  ['invalid_payload', 'payload-failure'],
  ['unsupported_schema_version', 'payload-failure'],
  ['schema_version_conflict', 'payload-failure'],
  ['payload_too_large', 'payload-failure'],
  ['draft_not_found', 'draft-not-found'],
  ['checkout_conflict', 'conflict'],
  ['initialization_conflict', 'conflict'],
  ['base_revision_conflict', 'conflict'],
  ['stale_client_revision', 'conflict'],
  ['revision_conflict', 'conflict'],
  ['draft_terminal', 'terminal-generation'],
  ['draft_closed', 'terminal-generation'],
  ['draft_expired', 'terminal-generation'],
  ['canonical_action_not_found', 'canonical-outcome-unknown'],
  ['canonical_action_conflict', 'conflict'],
  ['canonical_intent_conflict', 'conflict'],
  ['canonical_action_consumed', 'conflict'],
  ['canonical_generation_not_closed', 'conflict'],
  ['draft_limit_reached', 'rate-limited'],
  ['internal_error', 'temporary-server-failure'],
]);

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const isNonEmptyString = (value) => typeof value === 'string' && value.length > 0;
const isPositiveInteger = (value) => Number.isInteger(value) && value > 0;
const hasOwn = (value, property) => Object.prototype.hasOwnProperty.call(value, property);
const hasExactKeys = (value, expected) => {
  if (!isObject(value)) {
    return false;
  }

  const actual = Object.keys(value).sort();
  const sortedExpected = [...expected].sort();

  return actual.length === expected.length
    && actual.every((key, index) => key === sortedExpected[index]);
};

const assertStringProperties = (value, properties) => properties.every(
  (property) => isNonEmptyString(value[property]),
);

const assertDraftMetadata = (value, payloadSchemaNullable = false) => {
  if (!isObject(value) || !assertStringProperties(value, [
    'continuation_id',
    'generation_id',
    'context',
    'target_id',
    'base_revision',
    'current_base_revision',
    'classification',
    'updated_at',
    'expires_at',
  ])) {
    return false;
  }

  if (!['current', 'stale'].includes(value.classification)
    || !Number.isInteger(value.client_revision)
    || value.client_revision < 0) {
    return false;
  }

  return payloadSchemaNullable
    ? value.payload_schema_version === null || isPositiveInteger(value.payload_schema_version)
    : isPositiveInteger(value.payload_schema_version);
};

const validateData = (operation, data) => {
  switch (operation) {
    case 'initialize':
    case 'initializeCreate':
      return isObject(data)
        && assertStringProperties(data, [
          'continuation_id',
          'generation_id',
          'context',
          'target_id',
          'base_revision',
        ])
        && isPositiveInteger(data.payload_schema_version);

    case 'preserve':
      return isObject(data) && ['accepted', 'idempotent'].includes(data.status);

    case 'detect':
      return data === null || assertDraftMetadata(data);

    case 'read':
      return assertDraftMetadata(data, true)
        && isNonEmptyString(data.created_at)
        && hasOwn(data, 'payload')
        && (data.payload === null || typeof data.payload === 'object');

    case 'discard':
      return isObject(data) && ['discarded', 'idempotent'].includes(data.status);

    case 'prepareCanonicalAction':
      return hasExactKeys(data, ['operation_id', 'intent', 'outcome', 'expires_at'])
        && assertStringProperties(data, ['operation_id', 'intent', 'expires_at'])
        && data.outcome === 'pending';

    case 'getCanonicalActionOutcome':
      if (!hasExactKeys(data, [
        'operation_id',
        'context',
        'target_id',
        'continuation_id',
        'generation_id',
        'intent',
        'outcome',
        'expected_base_revision',
        'final_target_id',
        'final_base_revision',
        'failure_code',
        'created_at',
        'updated_at',
        'expires_at',
        'completed_at',
      ])
        || !assertStringProperties(data, [
          'operation_id',
          'context',
          'target_id',
          'continuation_id',
          'generation_id',
          'intent',
          'outcome',
          'expected_base_revision',
          'created_at',
          'updated_at',
          'expires_at',
        ])) {
        return false;
      }

      if (!['pending', 'successful', 'failed', 'unknown'].includes(data.outcome)
        || !['final_target_id', 'final_base_revision', 'failure_code', 'completed_at'].every(
          (key) => data[key] === null || isNonEmptyString(data[key]),
        )) {
        return false;
      }

      if (data.outcome === 'pending') {
        return data.final_target_id === null
          && data.final_base_revision === null
          && data.failure_code === null
          && data.completed_at === null;
      }

      if (data.outcome === 'successful') {
        return isNonEmptyString(data.final_target_id)
          && isNonEmptyString(data.final_base_revision)
          && data.failure_code === null
          && isNonEmptyString(data.completed_at);
      }

      if (data.outcome === 'failed') {
        return data.final_target_id === null
          && data.final_base_revision === null
          && isNonEmptyString(data.failure_code)
          && isNonEmptyString(data.completed_at);
      }

      return data.final_target_id === null
        && data.final_base_revision === null
        && data.failure_code === null
        && isNonEmptyString(data.completed_at);

    default:
      return false;
  }
};

const validateRequest = (operation, request) => {
  switch (operation) {
    case 'initialize':
      return hasExactKeys(request, ['context', 'target_id', 'initialization_key'])
        && assertStringProperties(request, ['context', 'target_id', 'initialization_key']);

    case 'initializeCreate':
      return hasExactKeys(request, ['context', 'initialization_key'])
        && assertStringProperties(request, ['context', 'initialization_key']);

    case 'preserve':
      return hasExactKeys(request, [
        'continuation_id',
        'generation_id',
        'client_revision',
        'payload_schema_version',
        'payload',
      ])
        && assertStringProperties(request, ['continuation_id', 'generation_id'])
        && isPositiveInteger(request.client_revision)
        && isPositiveInteger(request.payload_schema_version);

    case 'detect':
      return hasExactKeys(request, ['context', 'target_id'])
        && assertStringProperties(request, ['context', 'target_id']);

    case 'read':
    case 'discard':
      return hasExactKeys(request, ['continuation_id', 'generation_id'])
        && assertStringProperties(request, ['continuation_id', 'generation_id']);

    case 'prepareCanonicalAction':
      return hasExactKeys(request, [
        'context',
        'target_id',
        'continuation_id',
        'generation_id',
        'client_revision',
        'payload_schema_version',
        'payload',
        'intent',
        'expected_base_revision',
      ])
        && assertStringProperties(request, [
          'context',
          'target_id',
          'continuation_id',
          'generation_id',
          'intent',
          'expected_base_revision',
        ])
        && isPositiveInteger(request.client_revision)
        && isPositiveInteger(request.payload_schema_version)
        && isObject(request.payload);

    case 'getCanonicalActionOutcome':
      return hasExactKeys(request, ['operation_id', 'context', 'target_id'])
        && assertStringProperties(request, ['operation_id', 'context', 'target_id']);

    default:
      return false;
  }
};

const parseRetryAfter = (value, now) => {
  if (!isNonEmptyString(value)) {
    return null;
  }

  if (/^\d+$/.test(value.trim())) {
    return Number.parseInt(value, 10) * 1000;
  }

  const timestamp = Date.parse(value);

  return Number.isNaN(timestamp) ? null : Math.max(0, timestamp - now());
};

/**
 * An error from the protected Autosave JSON API or its transport.
 */
export class AutosaveApiError extends Error {
  constructor(message, {
    code,
    classification,
    operation,
    status = 0,
    retryable = false,
    outcomeUnknown = false,
    retryAfter = null,
  }) {
    super(message);
    this.name = 'AutosaveApiError';
    this.code = code;
    this.classification = classification;
    this.operation = operation;
    this.status = status;
    this.retryable = retryable;
    this.outcomeUnknown = outcomeUnknown;
    this.retryAfter = retryAfter;
  }
}

const transportError = (operation, code, classification, options = {}) => new AutosaveApiError(
  'The Autosave request could not be confirmed.',
  {
    code,
    classification,
    operation,
    outcomeUnknown: MUTATION_OPERATIONS.has(operation),
    ...options,
  },
);

const apiError = (operation, status, error, retryAfter) => {
  let classification = ERROR_CLASSIFICATIONS.get(error.code);

  if (!classification && status === 401) {
    classification = 'authentication-required';
  } else if (!classification && status === 403) {
    classification = 'permission-denied';
  } else if (!classification && status === 429) {
    classification = 'rate-limited';
  } else if (!classification && status >= 500) {
    classification = 'temporary-server-failure';
  } else if (!classification) {
    classification = 'permanent-api-failure';
  }

  const retryable = classification === 'rate-limited'
    || classification === 'temporary-server-failure';

  return new AutosaveApiError('The Autosave API rejected the request.', {
    code: error.code,
    classification,
    operation,
    status,
    retryable,
    outcomeUnknown: false,
    retryAfter,
  });
};

/**
 * Strict client for the protected com_autosave administrator JSON API.
 *
 * Endpoint URLs and the current Joomla CSRF token are supplied by the future
 * component integration. This module intentionally reads no Joomla globals.
 */
export class AutosaveApiClient {
  constructor({
    endpoints,
    csrf,
    fetchImpl = globalThis.fetch?.bind(globalThis),
    requestTimeout = 15000,
    timers = globalThis,
    abortControllerFactory = () => new AbortController(),
    now = () => Date.now(),
  }) {
    if (!isObject(endpoints)
      || !REQUIRED_OPERATIONS.every((operation) => isNonEmptyString(endpoints[operation]))
      || (endpoints.initializeCreate !== undefined && !isNonEmptyString(endpoints.initializeCreate))) {
      throw new TypeError('Autosave API endpoints are required.');
    }

    if (!isNonEmptyString(csrf) || typeof fetchImpl !== 'function') {
      throw new TypeError('Autosave API transport configuration is invalid.');
    }

    if (!Number.isFinite(requestTimeout) || requestTimeout <= 0
      || typeof timers.setTimeout !== 'function'
      || typeof timers.clearTimeout !== 'function'
      || typeof abortControllerFactory !== 'function') {
      throw new TypeError('Autosave API timeout configuration is invalid.');
    }

    this.endpoints = { ...endpoints };
    this.csrf = csrf;
    this.fetchImpl = fetchImpl;
    this.requestTimeout = requestTimeout;
    this.timers = timers;
    this.abortControllerFactory = abortControllerFactory;
    this.now = now;
  }

  initialize(request, options) {
    return this.request('initialize', request, options);
  }

  initializeCreate(request, options) {
    return this.request('initializeCreate', request, options);
  }

  preserve(request, options) {
    return this.request('preserve', request, options);
  }

  detect(request, options) {
    return this.request('detect', request, options);
  }

  read(request, options) {
    return this.request('read', request, options);
  }

  discard(request, options) {
    return this.request('discard', request, options);
  }

  prepareCanonicalAction(request, options) {
    return this.request('prepareCanonicalAction', request, options);
  }

  getCanonicalActionOutcome(request, options) {
    return this.request('getCanonicalActionOutcome', request, options);
  }

  async request(operation, request, { signal } = {}) {
    if (!OPERATIONS.includes(operation)) {
      throw new TypeError('The Autosave API operation is unsupported.');
    }

    if (!validateRequest(operation, request)) {
      throw transportError(operation, 'invalid_request_payload', 'validation-failure', {
        outcomeUnknown: false,
      });
    }

    let body;

    try {
      body = JSON.stringify(request);
    } catch (error) {
      throw transportError(operation, 'invalid_request_payload', 'validation-failure', {
        outcomeUnknown: false,
      });
    }

    const controller = this.abortControllerFactory();
    let timedOut = false;
    const abortFromOwner = () => controller.abort();

    if (signal?.aborted) {
      controller.abort();
    } else {
      signal?.addEventListener('abort', abortFromOwner, { once: true });
    }

    const timeoutId = this.timers.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, this.requestTimeout);

    let response;

    try {
      response = await this.fetchImpl(this.endpoints[operation], {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': this.csrf,
        },
        body,
        signal: controller.signal,
      });
    } catch (error) {
      if (timedOut) {
        throw transportError(operation, 'request_timeout', 'request-timeout', {
          retryable: true,
        });
      }

      if (signal?.aborted || controller.signal.aborted) {
        throw transportError(operation, 'request_aborted', 'request-aborted');
      }

      throw transportError(operation, 'network_failure', 'network-failure', {
        retryable: true,
      });
    } finally {
      this.timers.clearTimeout(timeoutId);
      signal?.removeEventListener('abort', abortFromOwner);
    }

    if (response.redirected || response.type === 'opaqueredirect') {
      throw transportError(operation, 'authentication_required', 'authentication-required');
    }

    const contentType = response.headers?.get?.('Content-Type') || '';
    const mediaType = contentType.split(';', 1)[0].trim().toLowerCase();

    if (mediaType !== 'application/json') {
      const isHtml = mediaType === 'text/html' || mediaType === 'application/xhtml+xml';

      throw transportError(
        operation,
        isHtml ? 'authentication_required' : 'unexpected_content_type',
        isHtml ? 'authentication-required' : 'malformed-response',
        { retryable: !isHtml && response.ok },
      );
    }

    let envelope;

    try {
      envelope = await response.json();
    } catch (error) {
      throw transportError(operation, 'malformed_json_response', 'malformed-response', {
        retryable: response.ok || response.status >= 500,
      });
    }

    const retryAfter = parseRetryAfter(response.headers?.get?.('Retry-After'), this.now);

    if (!response.ok) {
      if (!isObject(envelope)
        || envelope.success !== false
        || !isObject(envelope.error)
        || !isNonEmptyString(envelope.error.code)
        || !isNonEmptyString(envelope.error.message)) {
        throw transportError(operation, 'malformed_error_envelope', 'malformed-response', {
          status: response.status,
          retryable: response.status >= 500 || response.status === 429,
          retryAfter,
        });
      }

      throw apiError(operation, response.status, envelope.error, retryAfter);
    }

    if (!isObject(envelope)
      || envelope.success !== true
      || !hasOwn(envelope, 'data')
      || !validateData(operation, envelope.data)) {
      throw transportError(operation, 'malformed_success_envelope', 'malformed-response', {
        status: response.status,
        retryable: true,
      });
    }

    return envelope.data;
  }
}
