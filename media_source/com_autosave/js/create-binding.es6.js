/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const VERSION = 1;
const HISTORY_KEY = 'joomlaAutosaveCreateForms';
const STORAGE_PREFIX = 'joomla.autosave.create.v1:';
const P1_PATTERN = /^p1:[a-f0-9]{64}$/;
const ID_PATTERN = /^[A-Za-z0-9._:-]{1,191}$/;

const defaultIdentityFactory = () => globalThis.crypto?.randomUUID?.()
  || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;

const isPlainObject = (value) => value !== null
  && typeof value === 'object'
  && !Array.isArray(value)
  && Object.getPrototypeOf(value) === Object.prototype;

/** Own the browser-local identity and advisory ownership of one blank Autosave form. */
export default class AutosaveCreateBinding {
  constructor({
    context,
    lineageKey = context,
    historySource = globalThis.history,
    storage,
    lockManager = globalThis.navigator?.locks,
    channelFactory = typeof globalThis.BroadcastChannel === 'function'
      ? (name) => new globalThis.BroadcastChannel(name)
      : null,
    identityFactory = defaultIdentityFactory,
  }) {
    if (typeof context !== 'string' || context.length === 0
      || typeof lineageKey !== 'string' || lineageKey.length === 0 || lineageKey.length > 255
      || /[\x00-\x1F\x7F]/.test(lineageKey)
      || typeof identityFactory !== 'function') {
      throw new TypeError('The Autosave create binding configuration is invalid.');
    }

    this.context = context;
    // A create lineage may be narrower than its API context (for example an
    // immutable server-owned creation descriptor). Keep that browser identity
    // separate without changing the authoritative context sent to the server.
    this.lineageKey = lineageKey;
    this.history = historySource;
    if (storage === undefined) {
      try {
        this.storage = globalThis.sessionStorage;
      } catch (error) {
        this.storage = null;
      }
    } else {
      this.storage = storage;
    }
    this.lockManager = lockManager;
    this.channelFactory = channelFactory;
    this.identityFactory = identityFactory;
    this.formInstanceId = this.resolveFormInstance();
    this.initializationKey = this.formInstanceId;
    this.targetId = null;
    this.releaseLock = null;
    this.lockPending = null;
    this.channel = null;
    this.conflictSubscribers = new Set();
    this.ownerInstanceId = defaultIdentityFactory();
    this.restoreBinding();
  }

  descriptor() {
    return Object.freeze({
      initializationKey: this.initializationKey,
      targetId: this.targetId,
      formInstanceId: this.formInstanceId,
      onInitialized: (identity) => this.bind(identity),
      acquire: (targetId) => this.acquire(targetId),
      release: () => this.release(),
      onCanonicalSuccess: (result) => this.canonicalSuccess(result),
      subscribeConflict: (callback) => this.subscribeConflict(callback),
    });
  }

  resolveFormInstance() {
    const current = isPlainObject(this.history?.state) ? this.history.state : {};
    const forms = isPlainObject(current[HISTORY_KEY]) ? current[HISTORY_KEY] : {};
    const existing = forms[this.lineageKey];

    if (typeof existing === 'string' && ID_PATTERN.test(existing)) {
      return existing;
    }

    const identity = this.identityFactory();

    if (typeof identity !== 'string' || !ID_PATTERN.test(identity)) {
      throw new TypeError('The Autosave form-instance identity is invalid.');
    }

    this.history?.replaceState?.({
      ...current,
      [HISTORY_KEY]: { ...forms, [this.lineageKey]: identity },
    }, '');

    return identity;
  }

  restoreBinding() {
    let decoded;

    try {
      decoded = JSON.parse(this.storage?.getItem?.(this.storageKey()) || 'null');
    } catch (error) {
      decoded = null;
    }

    if (!isPlainObject(decoded)
      || decoded.version !== VERSION
      || decoded.context !== this.context
      || decoded.formInstanceId !== this.formInstanceId
      || typeof decoded.initializationKey !== 'string'
      || !ID_PATTERN.test(decoded.initializationKey)
      || typeof decoded.targetId !== 'string'
      || !P1_PATTERN.test(decoded.targetId)) {
      this.removeStoredBinding();

      return;
    }

    this.initializationKey = decoded.initializationKey;
    this.targetId = decoded.targetId;
  }

  bind(identity) {
    if (!isPlainObject(identity) || identity.context !== this.context || !P1_PATTERN.test(identity.target_id)) {
      throw new TypeError('The provisional Autosave identity is invalid.');
    }

    this.targetId = identity.target_id;
    try {
      this.storage?.setItem?.(this.storageKey(), JSON.stringify({
        version: VERSION,
        context: this.context,
        formInstanceId: this.formInstanceId,
        initializationKey: this.initializationKey,
        targetId: this.targetId,
      }));
    } catch (error) {
      // Server state remains authoritative when browser storage is unavailable.
    }
  }

  async acquire(targetId) {
    if (!P1_PATTERN.test(targetId) || (this.targetId !== null && targetId !== this.targetId)) {
      return false;
    }

    if (this.releaseLock) {
      return true;
    }

    if (!this.channel) {
      this.channel = this.channelFactory?.(`${STORAGE_PREFIX}${this.context}`) || null;

      if (this.channel) {
        this.channel.onmessage = ({ data }) => this.handleChannelMessage(data);
      }
    }

    if (!this.lockManager?.request) {
      this.announceOwnership();

      return true;
    }

    if (!this.lockPending) {
      this.lockPending = new Promise((resolve) => {
        let settled = false;
        const settle = (value) => {
          if (!settled) {
            settled = true;
            resolve(value);
          }
        };
        void this.lockManager.request(`${STORAGE_PREFIX}${this.context}:${targetId}`, { ifAvailable: true }, async (lock) => {
          if (!lock) {
            settle(false);

            return;
          }

          settle(true);
          await new Promise((release) => {
            this.releaseLock = release;
          });
        }).catch(() => settle(false));
      });
    }

    const acquired = await this.lockPending;
    this.lockPending = null;

    if (acquired) {
      this.announceOwnership();
    }

    return acquired;
  }

  canonicalSuccess({ intent }) {
    this.removeStoredBinding();
    this.release();

    if (intent === 'save-new') {
      this.rotate();
    }
  }

  rotate() {
    const current = isPlainObject(this.history?.state) ? this.history.state : {};
    const forms = isPlainObject(current[HISTORY_KEY]) ? current[HISTORY_KEY] : {};
    const identity = this.identityFactory();

    if (typeof identity !== 'string' || !ID_PATTERN.test(identity)) {
      return false;
    }

    this.history?.replaceState?.({
      ...current,
      [HISTORY_KEY]: { ...forms, [this.lineageKey]: identity },
    }, '');
    this.formInstanceId = identity;
    this.initializationKey = identity;
    this.targetId = null;

    return true;
  }

  release() {
    this.releaseLock?.();
    this.releaseLock = null;
    this.channel?.close?.();
    this.channel = null;
  }

  subscribeConflict(callback) {
    if (typeof callback !== 'function') {
      throw new TypeError('The Autosave ownership-conflict callback is invalid.');
    }

    this.conflictSubscribers.add(callback);

    return () => this.conflictSubscribers.delete(callback);
  }

  announceOwnership() {
    this.channel?.postMessage?.({
      version: VERSION,
      type: 'owner',
      context: this.context,
      formInstanceId: this.formInstanceId,
      ownerInstanceId: this.ownerInstanceId,
    });
  }

  handleChannelMessage(message) {
    if (!isPlainObject(message)
      || message.version !== VERSION
      || message.type !== 'owner'
      || message.context !== this.context
      || message.formInstanceId !== this.formInstanceId
      || typeof message.ownerInstanceId !== 'string'
      || !ID_PATTERN.test(message.ownerInstanceId)
      || message.ownerInstanceId === this.ownerInstanceId) {
      return;
    }

    [...this.conflictSubscribers].forEach((callback) => callback());
  }

  removeStoredBinding() {
    try {
      this.storage?.removeItem?.(this.storageKey());
    } catch (error) {
      // Canonical success remains authoritative if browser cleanup is unavailable.
    }
  }

  storageKey() {
    return `${STORAGE_PREFIX}${this.context}:${this.formInstanceId}`;
  }
}

export { HISTORY_KEY, P1_PATTERN, STORAGE_PREFIX };
