/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { AutosaveApiClient, AutosaveApiError } from '../src/api-client.es6.js';
import { createPayloadSnapshot, validateAutosaveAdapter } from '../src/adapter.es6.js';

export { AutosaveApiClient, AutosaveApiError, validateAutosaveAdapter };

export const AUTOSAVE_STATE_EVENT = 'joomla:autosave-statechange';
export const AUTOSAVE_DRAFT_EVENT = 'joomla:autosave-draftdetected';

export const DEFAULT_AUTOSAVE_CONFIGURATION = Object.freeze({
  debounceInterval: 1500,
  maximumDirtyAge: 10000,
  retryInitialDelay: 1000,
  retryMaximumDelay: 30000,
  maximumRetryAttempts: 5,
  detectOnStart: true,
});

const defaultIdentityFactory = () => {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID();
  }

  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
};

const defaultClock = {
  now: () => Date.now(),
  setTimeout: (callback, delay) => globalThis.setTimeout(callback, delay),
  clearTimeout: (timer) => globalThis.clearTimeout(timer),
};

const safeError = (error) => ({
  code: typeof error?.code === 'string' ? error.code : 'runtime_failure',
  classification: typeof error?.classification === 'string'
    ? error.classification
    : 'runtime-failure',
  retryable: error?.retryable === true,
  retryAfter: Number.isFinite(error?.retryAfter) ? error.retryAfter : null,
});

const sameCandidate = (first, second) => first
  && second
  && first.continuation_id === second.continuation_id
  && first.generation_id === second.generation_id;

const validateConfiguration = (configuration) => {
  const merged = { ...DEFAULT_AUTOSAVE_CONFIGURATION, ...configuration };
  const durations = [
    merged.debounceInterval,
    merged.maximumDirtyAge,
    merged.retryInitialDelay,
    merged.retryMaximumDelay,
  ];

  if (durations.some((value) => !Number.isFinite(value) || value < 0)
    || !Number.isInteger(merged.maximumRetryAttempts)
    || merged.maximumRetryAttempts < 1) {
    throw new TypeError('The Autosave runtime scheduling configuration is invalid.');
  }

  return Object.freeze(merged);
};

/**
 * Headless, component-neutral coordinator for separate Autosave drafts.
 *
 * The adapter owns field allow-listing and editor integration. The caller owns
 * endpoint/script-option discovery and decides where this runtime is activated.
 */
export class AutosaveRuntime {
  constructor({
    apiClient,
    adapter,
    context,
    targetId,
    schemaVersion,
    eventTarget = new EventTarget(),
    eventFactory = (name, detail) => new CustomEvent(name, { detail }),
    clock = defaultClock,
    identityFactory = defaultIdentityFactory,
    onlineSource = globalThis.window,
    visibilitySource = globalThis.document,
    isOnline = () => globalThis.navigator?.onLine !== false,
    isHidden = () => globalThis.document?.visibilityState === 'hidden',
    abortControllerFactory = () => new AbortController(),
    configuration = {},
  }) {
    if (!apiClient
      || !['initialize', 'preserve', 'detect', 'read', 'discard'].every(
        (method) => typeof apiClient[method] === 'function',
      )) {
      throw new TypeError('The Autosave API client contract is invalid.');
    }

    validateAutosaveAdapter(adapter);

    if (typeof context !== 'string' || context.length === 0
      || typeof targetId !== 'string' || targetId.length === 0
      || !Number.isInteger(schemaVersion) || schemaVersion <= 0
      || !eventTarget || typeof eventTarget.dispatchEvent !== 'function'
      || typeof eventFactory !== 'function'
      || typeof clock.now !== 'function'
      || typeof clock.setTimeout !== 'function'
      || typeof clock.clearTimeout !== 'function'
      || typeof identityFactory !== 'function'
      || typeof isOnline !== 'function'
      || typeof isHidden !== 'function'
      || typeof abortControllerFactory !== 'function') {
      throw new TypeError('The Autosave runtime configuration is invalid.');
    }

    this.apiClient = apiClient;
    this.adapter = adapter;
    this.context = context;
    this.targetId = targetId;
    this.schemaVersion = schemaVersion;
    this.eventTarget = eventTarget;
    this.eventFactory = eventFactory;
    this.clock = clock;
    this.onlineSource = onlineSource;
    this.visibilitySource = visibilitySource;
    this.isOnline = isOnline;
    this.isHidden = isHidden;
    this.abortControllerFactory = abortControllerFactory;
    this.configuration = validateConfiguration(configuration);

    this.runtimeIdentity = identityFactory();
    this.initializationKey = identityFactory();

    if (typeof this.runtimeIdentity !== 'string' || this.runtimeIdentity.length === 0
      || typeof this.initializationKey !== 'string' || this.initializationKey.length === 0) {
      throw new TypeError('The Autosave identity factory returned an invalid identity.');
    }

    this.lifecycle = 'idle';
    this.status = 'clean';
    this.started = false;
    this.destroyed = false;
    this.adapterDestroyed = false;
    this.unsubscribe = null;
    this.controllers = new Set();
    this.activeMutation = null;
    this.recoveryOperation = null;
    this.capturePending = false;
    this.suppressChanges = false;
    this.detectionComplete = false;
    this.detectionStarted = false;
    this.terminal = false;

    this.identity = null;
    this.baseRevision = null;
    this.nextClientRevision = 1;
    this.lastAcknowledgedRevision = 0;
    this.lastSuccessfulAt = null;

    this.changeSequence = 0;
    this.dirty = false;
    this.dirtySince = null;
    this.debounceDeadline = null;
    this.maximumDirtyDeadline = null;
    this.debounceTimer = null;
    this.maximumDirtyTimer = null;
    this.retryTimer = null;
    this.pendingSnapshot = null;
    this.forceEligible = false;

    this.retryAttempt = 0;
    this.retryAt = null;
    this.retryCallback = null;
    this.lastError = null;
    this.recoveryCandidate = null;
    this.ignoredCandidate = null;

    this.handleMeaningfulChange = this.handleMeaningfulChange.bind(this);
    this.handleOnline = this.handleOnline.bind(this);
    this.handleOffline = this.handleOffline.bind(this);
    this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
  }

  get state() {
    const candidate = this.recoveryCandidate
      ? {
        context: this.recoveryCandidate.context,
        targetId: this.recoveryCandidate.target_id,
        classification: this.recoveryCandidate.classification,
        clientRevision: this.recoveryCandidate.client_revision,
        schemaVersion: this.recoveryCandidate.payload_schema_version,
        updatedAt: this.recoveryCandidate.updated_at,
        expiresAt: this.recoveryCandidate.expires_at,
        localEdits: this.dirty,
      }
      : null;

    return {
      context: this.context,
      targetId: this.targetId,
      lifecycle: this.lifecycle,
      status: this.status,
      dirty: this.dirty,
      changeSequence: this.changeSequence,
      clientRevision: this.pendingSnapshot?.revision || this.nextClientRevision - 1,
      lastAcknowledgedRevision: this.lastAcknowledgedRevision,
      baseRevision: this.baseRevision,
      hasNewerChanges: this.pendingSnapshot
        ? this.changeSequence > this.pendingSnapshot.changeSequence
        : this.dirty,
      lastSuccessfulAt: this.lastSuccessfulAt,
      retryAttempt: this.retryAttempt,
      retryAt: this.retryAt,
      error: this.lastError ? { ...this.lastError } : null,
      recoveryCandidate: candidate,
    };
  }

  start() {
    if (this.started || this.destroyed) {
      return this;
    }

    this.started = true;
    const unsubscribe = this.adapter.subscribe(this.handleMeaningfulChange);

    if (typeof unsubscribe !== 'function') {
      this.started = false;
      throw new TypeError('The Autosave adapter must return an unsubscribe callback.');
    }

    this.unsubscribe = unsubscribe;
    this.onlineSource?.addEventListener?.('online', this.handleOnline);
    this.onlineSource?.addEventListener?.('offline', this.handleOffline);
    this.visibilitySource?.addEventListener?.('visibilitychange', this.handleVisibilityChange);

    if (this.configuration.detectOnStart) {
      if (this.isOnline()) {
        this.detect();
      } else {
        this.lifecycle = 'active';
        this.status = 'offline';
        this.emitState();
      }
    } else {
      this.detectionComplete = true;
      this.lifecycle = 'active';
      this.emitState();
    }

    return this;
  }

  async detect() {
    if (this.destroyed || this.detectionStarted || this.detectionComplete) {
      return;
    }

    if (!this.isOnline()) {
      this.lifecycle = 'active';
      this.status = 'offline';
      this.retryCallback = () => this.detect();
      this.emitState();

      return;
    }

    this.detectionStarted = true;
    this.lifecycle = 'detecting';
    this.status = this.dirty ? 'dirty' : 'detecting';
    this.lastError = null;
    this.emitState();

    const controller = this.createController();

    try {
      const candidate = await this.apiClient.detect({
        context: this.context,
        target_id: this.targetId,
      }, { signal: controller.signal });

      if (this.destroyed) {
        return;
      }

      this.detectionStarted = false;
      this.detectionComplete = true;
      this.retryAttempt = 0;
      this.retryCallback = null;
      this.retryAt = null;
      this.lifecycle = 'active';

      if (candidate && !sameCandidate(candidate, this.ignoredCandidate)) {
        if (candidate.context !== this.context || candidate.target_id !== this.targetId) {
          throw this.runtimeError('detected_identity_mismatch', 'malformed-response');
        }

        this.recoveryCandidate = candidate;
        this.clearDirtyTimers();
        this.status = 'recovery-required';
        this.emitState();
        this.emitDraftDetected();

        return;
      }

      this.status = this.dirty ? 'waiting-debounce' : 'clean';
      this.emitState();

      if (this.dirty) {
        this.scheduleDirty();
      }
    } catch (error) {
      if (this.destroyed) {
        return;
      }

      this.detectionStarted = false;
      this.detectionComplete = false;
      this.lifecycle = 'active';
      this.handleFailure(error, () => this.detect());
    } finally {
      this.releaseController(controller);
    }
  }

  handleMeaningfulChange() {
    if (this.destroyed || this.suppressChanges) {
      return;
    }

    const now = this.clock.now();
    this.changeSequence += 1;
    this.dirty = true;

    if (this.dirtySince === null) {
      this.dirtySince = now;
      this.maximumDirtyDeadline = now + this.configuration.maximumDirtyAge;
    }

    this.debounceDeadline = now + this.configuration.debounceInterval;

    if (this.status === 'paused') {
      this.retryAttempt = 0;
      this.lastError = null;

      if (this.retryCallback) {
        const callback = this.retryCallback;
        this.retryCallback = null;
        callback();

        return;
      }
    }

    if (!this.isBlocked()) {
      this.status = 'waiting-debounce';
    }

    this.emitState();
    this.scheduleDirty();
  }

  handleOffline() {
    if (this.destroyed) {
      return;
    }

    this.clearRetryTimer();
    this.status = 'offline';
    this.emitState();
  }

  handleOnline() {
    if (this.destroyed || !this.isOnline()) {
      return;
    }

    if (this.status === 'authentication-required'
      || this.status === 'conflict'
      || this.status === 'terminal') {
      return;
    }

    if (this.retryCallback) {
      const callback = this.retryCallback;
      this.retryCallback = null;
      this.retryAttempt = 0;
      this.status = this.recoveryCandidate
        ? 'recovery-required'
        : (this.dirty ? 'waiting-debounce' : 'clean');
      callback();

      return;
    }

    if (this.recoveryCandidate) {
      return;
    }

    if (!this.detectionComplete) {
      const callback = this.retryCallback;
      this.retryCallback = null;
      this.retryAttempt = 0;
      (callback || (() => this.detect()))();

      return;
    }

    if (this.dirty) {
      this.status = 'waiting-debounce';
      this.forceEligible = true;
      this.beginEligiblePreservation();
    } else {
      this.status = this.lastAcknowledgedRevision > 0 ? 'preserved' : 'clean';
      this.emitState();
    }
  }

  handleVisibilityChange() {
    if (this.destroyed || !this.isHidden() || !this.dirty || this.isBlocked()) {
      return;
    }

    this.forceEligible = true;
    this.clearDirtyTimers();
    this.beginEligiblePreservation();
  }

  async restoreDetectedDraft() {
    const candidate = this.requireRecoveryCandidate();

    if (!candidate || this.recoveryOperation || this.activeMutation) {
      return false;
    }

    if (!this.isOnline()) {
      this.status = 'offline';
      this.emitState();

      return false;
    }

    this.recoveryOperation = 'read';
    this.status = 'recovery-applying';
    this.lastError = null;
    this.emitState();
    const controller = this.createController();

    try {
      const recovered = await this.apiClient.read({
        continuation_id: candidate.continuation_id,
        generation_id: candidate.generation_id,
      }, { signal: controller.signal });

      if (this.destroyed) {
        return false;
      }

      if (!sameCandidate(recovered, candidate)
        || recovered.context !== this.context
        || recovered.target_id !== this.targetId
        || recovered.payload_schema_version !== this.schemaVersion
        || recovered.payload === null) {
        throw this.runtimeError('recovered_payload_mismatch', 'payload-failure');
      }

      this.suppressChanges = true;

      try {
        await this.adapter.apply(recovered.payload);
      } finally {
        this.suppressChanges = false;
      }

      if (this.destroyed) {
        return false;
      }

      this.identity = {
        continuationId: recovered.continuation_id,
        generationId: recovered.generation_id,
      };
      this.baseRevision = recovered.base_revision;
      this.lastAcknowledgedRevision = recovered.client_revision;
      this.nextClientRevision = recovered.client_revision + 1;
      this.pendingSnapshot = null;
      this.recoveryCandidate = null;
      this.dirty = false;
      this.dirtySince = null;
      this.debounceDeadline = null;
      this.maximumDirtyDeadline = null;
      this.clearDirtyTimers();
      this.retryAttempt = 0;
      this.retryCallback = null;
      this.clearRetryTimer();
      this.lastError = null;
      this.status = 'preserved';
      this.emitState();

      return true;
    } catch (error) {
      if (!this.destroyed) {
        this.handleFailure(error, () => this.restoreDetectedDraft(), true);
      }

      return false;
    } finally {
      this.recoveryOperation = null;
      this.releaseController(controller);
    }
  }

  async discardDetectedDraft() {
    const candidate = this.requireRecoveryCandidate();

    if (!candidate || this.recoveryOperation || this.activeMutation) {
      return false;
    }

    if (!this.isOnline()) {
      this.status = 'offline';
      this.emitState();

      return false;
    }

    this.recoveryOperation = 'discard';
    this.activeMutation = 'discard';
    this.status = 'recovery-discarding';
    this.lastError = null;
    this.emitState();
    const controller = this.createController();

    try {
      await this.apiClient.discard({
        continuation_id: candidate.continuation_id,
        generation_id: candidate.generation_id,
      }, { signal: controller.signal });

      if (this.destroyed) {
        return false;
      }

      this.recoveryCandidate = null;
      this.retryAttempt = 0;
      this.retryCallback = null;
      this.clearRetryTimer();
      this.lastError = null;
      this.status = this.dirty ? 'waiting-debounce' : 'clean';
      this.emitState();

      if (this.dirty) {
        this.scheduleDirty();
      }

      return true;
    } catch (error) {
      if (!this.destroyed) {
        this.handleFailure(error, () => this.discardDetectedDraft(), true);
      }

      return false;
    } finally {
      this.activeMutation = null;
      this.recoveryOperation = null;
      this.releaseController(controller);

      if (!this.destroyed && !this.recoveryCandidate && this.dirty) {
        this.scheduleDirty();
      }
    }
  }

  keepCurrent() {
    const candidate = this.requireRecoveryCandidate();

    if (!candidate || this.recoveryOperation) {
      return false;
    }

    this.ignoredCandidate = candidate;
    this.recoveryCandidate = null;
    this.lastError = null;
    this.retryAttempt = 0;
    this.retryCallback = null;
    this.clearRetryTimer();
    this.status = this.dirty ? 'waiting-debounce' : 'clean';
    this.emitState();

    if (this.dirty) {
      this.scheduleDirty();
    }

    return true;
  }

  retry() {
    if (this.destroyed || !this.isOnline() || this.terminal || this.recoveryOperation) {
      return false;
    }

    const callback = this.retryCallback;

    if (!callback) {
      return false;
    }

    this.clearRetryTimer();
    this.retryCallback = null;
    this.retryAttempt = 0;
    this.lastError = null;
    this.status = this.recoveryCandidate
      ? 'recovery-required'
      : (this.dirty ? 'waiting-debounce' : 'clean');
    callback();

    return true;
  }

  destroy() {
    if (this.destroyed) {
      return;
    }

    this.destroyed = true;
    this.lifecycle = 'destroyed';
    this.status = 'destroyed';
    this.clearDirtyTimers();
    this.clearRetryTimer();
    this.retryCallback = null;
    this.pendingSnapshot = null;
    this.onlineSource?.removeEventListener?.('online', this.handleOnline);
    this.onlineSource?.removeEventListener?.('offline', this.handleOffline);
    this.visibilitySource?.removeEventListener?.('visibilitychange', this.handleVisibilityChange);

    this.controllers.forEach((controller) => controller.abort());
    this.controllers.clear();

    if (this.unsubscribe) {
      const unsubscribe = this.unsubscribe;
      this.unsubscribe = null;

      try {
        unsubscribe();
      } catch (error) {
        // Destruction must continue even if component cleanup fails.
      }
    }

    if (!this.adapterDestroyed && this.adapter?.destroy) {
      this.adapterDestroyed = true;

      try {
        this.adapter.destroy();
      } catch (error) {
        // Destruction must remain idempotent and release runtime resources.
      }
    }

    this.eventTarget.dispatchEvent(this.eventFactory(AUTOSAVE_STATE_EVENT, this.state));
    this.adapter = null;
  }

  scheduleDirty() {
    if (!this.started || this.destroyed || !this.dirty || this.isBlocked()) {
      return;
    }

    if (!this.isOnline()) {
      this.status = 'offline';
      this.emitState();

      return;
    }

    if (this.activeMutation || this.capturePending) {
      return;
    }

    const now = this.clock.now();

    if (this.forceEligible
      || this.debounceDeadline === null
      || this.maximumDirtyDeadline === null
      || this.debounceDeadline <= now
      || this.maximumDirtyDeadline <= now) {
      this.beginEligiblePreservation();

      return;
    }

    if (this.debounceTimer !== null) {
      this.clock.clearTimeout(this.debounceTimer);
    }

    this.debounceTimer = this.clock.setTimeout(() => {
      this.debounceTimer = null;
      this.beginEligiblePreservation();
    }, this.debounceDeadline - now);

    if (this.maximumDirtyTimer === null) {
      this.maximumDirtyTimer = this.clock.setTimeout(() => {
        this.maximumDirtyTimer = null;
        this.beginEligiblePreservation();
      }, this.maximumDirtyDeadline - now);
    }
  }

  async beginEligiblePreservation() {
    if (this.destroyed || !this.dirty || this.isBlocked()) {
      return;
    }

    if (!this.isOnline()) {
      this.status = 'offline';
      this.emitState();

      return;
    }

    if (this.activeMutation || this.capturePending) {
      this.forceEligible = true;

      return;
    }

    this.forceEligible = false;
    this.clearDirtyTimers();

    if (!this.pendingSnapshot) {
      this.capturePending = true;
      const capturedSequence = this.changeSequence;
      let payload;

      try {
        payload = createPayloadSnapshot(await this.adapter.capture());
      } catch (error) {
        if (!this.destroyed) {
          this.lastError = {
            code: 'adapter_capture_failed',
            classification: 'payload-failure',
            retryable: false,
          };
          this.retryCallback = () => this.beginEligiblePreservation();
          this.status = 'error';
          this.emitState();
        }

        return;
      } finally {
        this.capturePending = false;
      }

      if (this.destroyed) {
        return;
      }

      this.pendingSnapshot = Object.freeze({
        revision: this.nextClientRevision,
        changeSequence: capturedSequence,
        payload,
      });
      this.nextClientRevision += 1;
    }

    if (this.identity) {
      this.preservePendingSnapshot();
    } else {
      this.initializeDraft();
    }
  }

  async initializeDraft() {
    if (this.destroyed || this.activeMutation || !this.pendingSnapshot || this.identity) {
      return;
    }

    this.activeMutation = 'initialize';
    this.lifecycle = 'initializing';
    this.status = 'initializing';
    this.lastError = null;
    this.emitState();
    const controller = this.createController();

    try {
      const identity = await this.apiClient.initialize({
        context: this.context,
        target_id: this.targetId,
        initialization_key: this.initializationKey,
      }, { signal: controller.signal });

      if (this.destroyed) {
        return;
      }

      if (identity.context !== this.context
        || identity.target_id !== this.targetId
        || identity.payload_schema_version !== this.schemaVersion) {
        throw this.runtimeError('initialized_identity_mismatch', 'conflict');
      }

      this.identity = {
        continuationId: identity.continuation_id,
        generationId: identity.generation_id,
      };
      this.baseRevision = identity.base_revision;
      this.retryAttempt = 0;
      this.retryAt = null;
      this.retryCallback = null;
      this.lastError = null;
      this.lifecycle = 'active';
    } catch (error) {
      if (!this.destroyed) {
        this.lifecycle = 'active';
        this.handleFailure(error, () => this.initializeDraft());
      }

      return;
    } finally {
      this.activeMutation = null;
      this.releaseController(controller);
    }

    this.preservePendingSnapshot();
  }

  async preservePendingSnapshot() {
    if (this.destroyed || this.activeMutation || !this.pendingSnapshot || !this.identity) {
      return;
    }

    const snapshot = this.pendingSnapshot;
    this.activeMutation = 'preserve';
    this.lifecycle = 'active';
    this.status = 'preserving';
    this.lastError = null;
    this.emitState();
    const controller = this.createController();

    try {
      await this.apiClient.preserve({
        continuation_id: this.identity.continuationId,
        generation_id: this.identity.generationId,
        client_revision: snapshot.revision,
        payload_schema_version: this.schemaVersion,
        payload: snapshot.payload,
      }, { signal: controller.signal });

      if (this.destroyed || this.pendingSnapshot !== snapshot) {
        return;
      }

      this.lastAcknowledgedRevision = snapshot.revision;
      this.lastSuccessfulAt = this.clock.now();
      this.pendingSnapshot = null;
      this.retryAttempt = 0;
      this.retryAt = null;
      this.retryCallback = null;
      this.lastError = null;

      if (this.changeSequence > snapshot.changeSequence) {
        this.dirty = true;
        this.status = 'waiting-debounce';
      } else {
        this.dirty = false;
        this.dirtySince = null;
        this.debounceDeadline = null;
        this.maximumDirtyDeadline = null;
        this.forceEligible = false;
        this.status = 'preserved';
      }

      this.emitState();
    } catch (error) {
      if (!this.destroyed) {
        this.handleFailure(error, () => this.preservePendingSnapshot());
      }

      return;
    } finally {
      this.activeMutation = null;
      this.releaseController(controller);
    }

    if (this.dirty) {
      this.scheduleDirty();
    }
  }

  handleFailure(error, retryCallback, recoveryPending = false) {
    this.lastError = safeError(error);
    this.retryCallback = retryCallback;

    if (!this.isOnline()) {
      this.status = 'offline';
      this.emitState();

      return;
    }

    switch (error?.classification) {
      case 'authentication-required':
        this.status = 'authentication-required';
        break;

      case 'conflict':
        this.status = 'conflict';
        break;

      case 'terminal-generation':
        if (recoveryPending) {
          this.status = 'recovery-required';
        } else {
          this.terminal = true;
          this.status = 'terminal';
        }
        break;

      case 'permission-denied':
      case 'csrf-failure':
      case 'validation-failure':
      case 'payload-failure':
      case 'draft-not-found':
      case 'permanent-api-failure':
        this.status = recoveryPending ? 'recovery-required' : 'error';
        break;

      case 'request-aborted':
        this.status = recoveryPending ? 'recovery-required' : 'error';
        break;

      default:
        if (error?.retryable === true || error?.outcomeUnknown === true) {
          this.scheduleRetry(retryCallback);

          return;
        }

        this.status = recoveryPending ? 'recovery-required' : 'error';
        break;
    }

    this.emitState();
  }

  scheduleRetry(callback) {
    this.retryAttempt += 1;
    this.retryCallback = callback;

    if (this.retryAttempt >= this.configuration.maximumRetryAttempts) {
      this.retryAt = null;
      this.status = 'paused';
      this.emitState();

      return;
    }

    const exponent = Math.max(0, this.retryAttempt - 1);
    const backoff = Math.min(
      this.configuration.retryInitialDelay * (2 ** exponent),
      this.configuration.retryMaximumDelay,
    );
    const retryAfter = Number.isFinite(this.lastError?.retryAfter)
      ? this.lastError.retryAfter
      : 0;
    const delay = Math.max(backoff, retryAfter);
    this.retryAt = this.clock.now() + delay;
    this.status = 'retry-waiting';
    this.emitState();
    this.clearRetryTimer();
    this.retryAt = this.clock.now() + delay;
    this.retryTimer = this.clock.setTimeout(() => {
      this.retryTimer = null;

      if (this.destroyed || !this.isOnline()) {
        return;
      }

      const retry = this.retryCallback;
      this.retryCallback = null;
      retry?.();
    }, delay);
  }

  isBlocked() {
    return this.destroyed
      || !this.started
      || !this.detectionComplete
      || this.recoveryCandidate !== null
      || this.terminal
      || [
        'authentication-required',
        'conflict',
        'error',
        'offline',
        'paused',
        'retry-waiting',
      ].includes(this.status);
  }

  requireRecoveryCandidate() {
    if (this.destroyed || !this.recoveryCandidate) {
      return null;
    }

    return this.recoveryCandidate;
  }

  runtimeError(code, classification) {
    const error = new Error('The Autosave runtime response was inconsistent.');
    error.code = code;
    error.classification = classification;
    error.retryable = false;
    error.outcomeUnknown = false;

    return error;
  }

  createController() {
    const controller = this.abortControllerFactory();
    this.controllers.add(controller);

    return controller;
  }

  releaseController(controller) {
    this.controllers.delete(controller);
  }

  clearDirtyTimers() {
    if (this.debounceTimer !== null) {
      this.clock.clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }

    if (this.maximumDirtyTimer !== null) {
      this.clock.clearTimeout(this.maximumDirtyTimer);
      this.maximumDirtyTimer = null;
    }
  }

  clearRetryTimer() {
    if (this.retryTimer !== null) {
      this.clock.clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
  }

  emitState() {
    if (this.destroyed) {
      return;
    }

    this.eventTarget.dispatchEvent(this.eventFactory(AUTOSAVE_STATE_EVENT, this.state));
  }

  emitDraftDetected() {
    if (this.destroyed) {
      return;
    }

    this.eventTarget.dispatchEvent(this.eventFactory(AUTOSAVE_DRAFT_EVENT, this.state));
  }
}
