/**
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Report a subscriber error without interrupting the remaining subscribers.
 *
 * @param {Error} error Subscriber error.
 *
 * @returns {void}
 *
 * @private
 */
const reportSubscriberError = (error) => {
  if (typeof globalThis.reportError === 'function') {
    globalThis.reportError(error);

    return;
  }

  queueMicrotask(() => {
    throw error;
  });
};

/**
 * A provider-neutral dirty notification. It receives no editor value or provider instance.
 * Consumers can obtain current content separately with JoomlaEditorDecorator.getValue().
 *
 * @callback JoomlaEditorChangeCallback
 *
 * @returns {void}
 */

/**
 * Error thrown when an editor provider does not support change observation.
 *
 * @property {string} code The stable `unsupported_change_observation` error code.
 */
class JoomlaEditorChangeObservationError extends Error {
  constructor() {
    super('The editor does not support change observation');
    this.name = 'JoomlaEditorChangeObservationError';
    this.code = 'unsupported_change_observation';
  }
}

/**
 * A decorator for Editor instance.
 */
export default class JoomlaEditorDecorator {
  /**
   * Internal! The property should not be accessed directly.
   * The editor instance.
   * @type {Object}
   */
  // instance = null;

  /**
   * Internal! The property should not be accessed directly.
   * The editor type/name, eg: tinymce, codemirror, none etc.
   * @type {string}
   */
  // type = '';

  /**
   * Internal! The property should not be accessed directly.
   * HTML ID of the editor.
   * @type {string}
   */
  // id = '';

  /**
   * Class constructor.
   *
   * @param {Object} instance The editor instance
   * @param {string} type The editor type/name
   * @param {string} id The editor ID
   */
  constructor(instance, type, id) {
    if (!instance || !type || !id) {
      throw new Error('Missed values for class constructor');
    }

    this.instance = instance;
    this.type = type;
    this.id = id;
    this.changeSubscriptions = new Map();
    this.changeObserverCleanup = null;
    this.changeNotificationSuppression = 0;
  }

  /**
   * Returns the editor instance object.
   *
   * @returns {Object}
   */
  getRawInstance() {
    return this.instance;
  }

  /**
   * Returns the editor type/name.
   *
   * @returns {string}
   */
  getType() {
    return this.type;
  }

  /**
   * Returns the editor id.
   *
   * @returns {string}
   */
  getId() {
    return this.id;
  }

  /**
   * Whether this editor supports public change observation.
   *
   * Third-party providers can support observation by overriding observeChanges().
   *
   * @returns {boolean}
   */
  supportsChangeObservation() {
    return this.observeChanges !== JoomlaEditorDecorator.prototype.observeChanges;
  }

  /**
   * Subscribe to live editor content changes.
   *
   * The callback is a dirty signal and receives no content or provider instance. Use getValue()
   * separately when the current value is needed. The returned function removes only this
   * subscription and can safely be called more than once. Providers may emit multiple dirty
   * signals for a complex edit, so consumers may coalesce or debounce notifications.
   *
   * @param {JoomlaEditorChangeCallback} callback Change callback.
   *
   * @returns {Function} Idempotent unsubscribe function.
   *
   * @throws {TypeError} When callback is not a function or provider cleanup is invalid.
   * @throws {JoomlaEditorChangeObservationError} When the provider does not support observation.
   */
  subscribeChange(callback) {
    if (typeof callback !== 'function') {
      throw new TypeError('The change callback must be a function');
    }

    if (!this.supportsChangeObservation()) {
      throw new JoomlaEditorChangeObservationError();
    }

    const subscription = Symbol('editor-change-subscription');
    this.changeSubscriptions.set(subscription, callback);

    if (!this.changeObserverCleanup) {
      let cleanup;

      try {
        cleanup = this.observeChanges(() => this.notifyChange());
      } catch (error) {
        this.changeSubscriptions.delete(subscription);
        throw error;
      }

      if (typeof cleanup !== 'function') {
        this.changeSubscriptions.delete(subscription);
        throw new TypeError('The editor change observer must return an unsubscribe function');
      }

      this.changeObserverCleanup = cleanup;
    }

    let subscribed = true;

    return () => {
      if (!subscribed) {
        return;
      }

      subscribed = false;
      this.changeSubscriptions.delete(subscription);

      if (this.changeSubscriptions.size === 0) {
        this.releaseChangeObserver();
      }
    };
  }

  /**
   * Attach the provider-specific live change observer.
   *
   * Providers supporting change observation must override this method and return an idempotent
   * cleanup function. The supplied callback must be called without editor content or internals.
   *
   * @param {JoomlaEditorChangeCallback} callback Provider-neutral dirty callback.
   *
   * @returns {Function}
   *
   * @protected
   */
  observeChanges(callback) {
    throw new JoomlaEditorChangeObservationError();
  }

  /**
   * Notify every public change subscriber without exposing editor content or internals.
   *
   * @returns {void}
   *
   * @protected
   */
  notifyChange() {
    if (this.changeNotificationSuppression > 0) {
      return;
    }

    [...this.changeSubscriptions.entries()].forEach(([subscription, callback]) => {
      if (!this.changeSubscriptions.has(subscription)) {
        return;
      }

      try {
        callback();
      } catch (error) {
        reportSubscriberError(error);
      }
    });
  }

  /**
   * Perform a public programmatic content change and emit one notification when the resulting
   * live value is different. Synchronous provider-native signals are suppressed while changing.
   *
   * Providers should use this helper from setValue() and other public content-changing methods.
   * Value comparison occurs only for an explicit programmatic change, never for live input.
   *
   * @param {Function} change Provider-specific change operation.
   *
   * @returns {void}
   *
   * @protected
   */
  performValueChange(change) {
    if (typeof change !== 'function') {
      throw new TypeError('The value change operation must be a function');
    }

    if (this.changeSubscriptions.size === 0) {
      change();

      return;
    }

    const previousValue = this.getValue();
    this.changeNotificationSuppression += 1;

    try {
      change();
    } finally {
      this.changeNotificationSuppression -= 1;
    }

    if (this.getValue() !== previousValue) {
      this.notifyChange();
    }
  }

  /**
   * Remove every public change subscription and the provider-specific observer.
   * Called by JoomlaEditor when an editor is unregistered.
   *
   * @returns {void}
   *
   * @internal
   */
  releaseChangeSubscriptions() {
    this.changeSubscriptions.clear();
    this.releaseChangeObserver();
  }

  /**
   * Remove the active provider observer.
   *
   * @returns {void}
   *
   * @private
   */
  releaseChangeObserver() {
    if (!this.changeObserverCleanup) {
      return;
    }

    const cleanup = this.changeObserverCleanup;
    this.changeObserverCleanup = null;
    cleanup();
  }

  /**
   * Return the complete data from the editor.
   * Should be implemented by editor provider.
   *
   * @returns {string}
   */
  getValue() {
    throw new Error('Not implemented');
  }

  /**
   * Replace the complete data of the editor
   * Should be implemented by editor provider.
   * Providers supporting change observation should use performValueChange() so a real
   * programmatic change produces one public dirty notification without synchronous duplicates.
   *
   * @param {string} value Value to set.
   *
   * @returns {JoomlaEditorDecorator}
   */
  setValue(value) {
    throw new Error('Not implemented');
  }

  /**
   * Return the selected text from the editor.
   * Should be implemented by editor provider.
   *
   * @returns {string}
   */
  getSelection() {
    throw new Error('Not implemented');
  }

  /**
   * Replace the selected text. If nothing selected, will insert the data at the cursor.
   * Should be implemented by editor provider.
   * Providers supporting change observation should use performValueChange() for the same
   * programmatic notification semantics as setValue().
   *
   * @param {string} value
   *
   * @returns {JoomlaEditorDecorator}
   */
  replaceSelection(value) {
    throw new Error('Not implemented');
  }

  /**
   * Toggles the editor disabled mode. When the editor is active then everything should be usable.
   * When inactive the editor should be unusable AND disabled for form validation.
   * Should be implemented by editor provider.
   *
   * @param {boolean} enable True to enable, false or undefined to disable.
   *
   * @returns {JoomlaEditorDecorator}
   */
  disable(enable) {
    throw new Error('Not implemented');
  }
}

export { JoomlaEditorChangeObservationError };
