/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Component-owned bridge used by AutosaveRuntime.
 *
 * @typedef {Object} AutosaveAdapter
 * @property {(onMeaningfulChange: Function) => Function} subscribe Register a
 *   meaningful-change listener and return an idempotent unsubscribe callback.
 * @property {() => *|Promise<*>} capture Return only allow-listed,
 *   JSON-serializable component state. It is called when preservation becomes
 *   eligible, never for each edit event.
 * @property {(payload: *) => void|Promise<void>} apply Validate and apply an
 *   explicitly selected recovery payload through supported component/editor APIs.
 * @property {() => void} [destroy] Release resources not owned by unsubscribe.
 */

/**
 * Validate the public adapter boundary without inspecting component internals.
 *
 * @param {AutosaveAdapter} adapter
 * @returns {AutosaveAdapter}
 */
export const validateAutosaveAdapter = (adapter) => {
  if (!adapter
    || typeof adapter.subscribe !== 'function'
    || typeof adapter.capture !== 'function'
    || typeof adapter.apply !== 'function'
    || (adapter.destroy !== undefined && typeof adapter.destroy !== 'function')) {
    throw new TypeError('The Autosave adapter contract is invalid.');
  }

  return adapter;
};

const assertJsonValue = (value, seen) => {
  if (value === null || typeof value === 'string' || typeof value === 'boolean') {
    return;
  }

  if (typeof value === 'number') {
    if (!Number.isFinite(value)) {
      throw new TypeError('The Autosave payload is not JSON serializable.');
    }

    return;
  }

  if (typeof value !== 'object' || seen.has(value)) {
    throw new TypeError('The Autosave payload is not JSON serializable.');
  }

  seen.add(value);

  if (Array.isArray(value)) {
    value.forEach((item) => assertJsonValue(item, seen));
  } else {
    if (Object.getPrototypeOf(value) !== Object.prototype) {
      throw new TypeError('The Autosave payload must contain plain data.');
    }

    Object.entries(value).forEach(([key, item]) => {
      if (typeof key !== 'string' || item === undefined) {
        throw new TypeError('The Autosave payload is not JSON serializable.');
      }

      assertJsonValue(item, seen);
    });
  }

  seen.delete(value);
};

const deepFreeze = (value) => {
  if (value && typeof value === 'object' && !Object.isFrozen(value)) {
    Object.freeze(value);
    Object.values(value).forEach(deepFreeze);
  }

  return value;
};

/**
 * Make a detached immutable snapshot for exactly one client revision.
 *
 * @param {*} payload
 * @returns {*}
 */
export const createPayloadSnapshot = (payload) => {
  assertJsonValue(payload, new Set());

  const encoded = JSON.stringify(payload);

  if (encoded === undefined) {
    throw new TypeError('The Autosave payload is not JSON serializable.');
  }

  return deepFreeze(JSON.parse(encoded));
};
