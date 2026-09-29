/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const PAYLOAD_KEYS = Object.freeze(['title', 'rules']);
const MAXIMUM_LENGTHS = Object.freeze({ title: 100 });
const MAXIMUM_GROUP_COUNT = 100;
const MAXIMUM_GROUP_ID = 2147483647;

const plain = (value) => value !== null && typeof value === 'object'
  && !Array.isArray(value) && Object.getPrototypeOf(value) === Object.prototype;

export const normalizeCanonicalId = (value) => {
  const id = Number.isSafeInteger(value) ? String(value) : value;
  if (typeof id !== 'string' || !/^[1-9][0-9]{0,9}$/.test(id) || Number(id) > 4294967295) {
    throw new TypeError('Invalid Access Level target.');
  }
  return id;
};

export const normalizeAutosaveTarget = (value) => (value === null
  || (typeof value === 'string' && /^p1:[a-f0-9]{64}$/.test(value))
  ? value : normalizeCanonicalId(value));

export const parseGroupId = (value) => {
  if (typeof value !== 'string' || !/^[1-9][0-9]{0,9}$/.test(value)) {
    throw new TypeError('The Access Level group field is invalid.');
  }
  const groupId = Number(value);
  if (!Number.isSafeInteger(groupId) || groupId > MAXIMUM_GROUP_ID) {
    throw new TypeError('The Access Level group field is invalid.');
  }
  return groupId;
};

export const sameRules = (first, second) => first.length === second.length
  && first.every((groupId, index) => groupId === second[index]);

export const snapshotMatches = (expected, applied) => expected.title === applied.title
  && sameRules(expected.rules, applied.rules);

export const validatePayload = (payload) => {
  if (!plain(payload)
    || Object.keys(payload).length !== PAYLOAD_KEYS.length
    || !PAYLOAD_KEYS.every((key) => Object.hasOwn(payload, key))
    || typeof payload.title !== 'string'
    || Array.from(payload.title).length > MAXIMUM_LENGTHS.title
    || !Array.isArray(payload.rules)
    || payload.rules.length > MAXIMUM_GROUP_COUNT
    || !payload.rules.every((groupId) => Number.isInteger(groupId)
      && groupId >= 1 && groupId <= MAXIMUM_GROUP_ID)) {
    throw new TypeError('The Access Level recovery payload is invalid.');
  }
  return { title: payload.title, rules: [...payload.rules] };
};

export default class LevelAutosaveAdapter {
  constructor({ descriptor, form, fields, eventFactory = (type) => new Event(type, { bubbles: true }) }) {
    if (!plain(descriptor) || !form || !plain(fields) || !fields.title
      || !Array.isArray(fields.rules) || fields.rules.length === 0
      || typeof eventFactory !== 'function') {
      throw new TypeError('The Access Level adapter configuration is invalid.');
    }
    this.descriptor = Object.freeze({
      context: descriptor.context,
      targetId: normalizeAutosaveTarget(descriptor.targetId),
      payloadSchemaVersion: descriptor.payloadSchemaVersion,
    });
    this.form = form;
    this.fields = { ...fields };
    this.eventFactory = eventFactory;
    this.baseline = null;
    this.callback = null;
    this.destroyed = false;
    this.suppressChanges = 0;
    this.listener = () => this.changed();
  }

  getDescriptor() { return this.descriptor; }

  read() {
    return {
      title: this.fields.title.value,
      rules: this.fields.rules.filter((control) => control.checked).map((control) => parseGroupId(control.value)),
    };
  }

  initializeBaseline() {
    this.assertCurrent();
    this.baseline = this.read();
    return this;
  }

  subscribe(callback) {
    if (typeof callback !== 'function' || this.callback || this.destroyed) {
      throw new TypeError('The Access Level subscription is invalid.');
    }
    if (!this.baseline) {
      this.initializeBaseline();
    }
    this.callback = callback;
    ['input', 'change'].forEach((type) => this.fields.title.addEventListener(type, this.listener));
    this.fields.rules.forEach((control) => control.addEventListener('change', this.listener));
    return () => this.unsubscribe();
  }

  capture() {
    this.assertCurrent();
    this.baseline = this.read();
    return { ...this.baseline, rules: [...this.baseline.rules] };
  }

  apply(payload) {
    const next = validatePayload(payload);
    this.assertCurrent();
    const previous = this.read();
    this.suppressChanges += 1;
    try {
      this.fields.title.value = next.title;
      this.fields.title.dispatchEvent(this.eventFactory('input'));
      this.fields.title.dispatchEvent(this.eventFactory('change'));
      this.fields.rules.forEach((control) => {
        const checked = next.rules.includes(parseGroupId(control.value));
        if (control.checked !== checked) {
          control.checked = checked;
          control.dispatchEvent(this.eventFactory('change'));
        }
      });
      const applied = this.read();
      if (!snapshotMatches(next, applied)) {
        throw new Error('The Access Level recovery payload could not be applied.');
      }
      this.baseline = applied;
    } catch (error) {
      try {
        this.fields.title.value = previous.title;
        this.fields.rules.forEach((control) => {
          control.checked = previous.rules.includes(parseGroupId(control.value));
        });
        this.baseline = previous;
      } catch (rollbackError) {
        Object.defineProperty(error, 'rollbackError', { value: rollbackError });
      }
      throw error;
    } finally {
      this.suppressChanges -= 1;
    }
  }

  changed() {
    if (this.destroyed || this.suppressChanges || !this.callback || !this.isCurrent()) {
      return;
    }
    let current;
    try {
      current = this.read();
    } catch {
      return;
    }
    if (this.baseline && snapshotMatches(this.baseline, current)) {
      return;
    }
    this.baseline = current;
    this.callback();
  }

  isCurrent() {
    return !this.destroyed && this.form?.isConnected
      && this.fields.title?.isConnected && this.form.contains(this.fields.title)
      && this.fields.rules.every((control) => control?.isConnected && this.form.contains(control));
  }

  assertCurrent() {
    if (!this.isCurrent()) {
      throw new Error('The Access Level form dependencies are stale.');
    }
  }

  unsubscribe() {
    if (!this.callback) {
      return;
    }
    ['input', 'change'].forEach((type) => this.fields.title.removeEventListener(type, this.listener));
    this.fields.rules.forEach((control) => control.removeEventListener('change', this.listener));
    this.callback = null;
  }

  destroy() {
    if (this.destroyed) {
      return;
    }
    this.unsubscribe();
    this.destroyed = true;
    this.form = null;
    this.fields = null;
  }
}

export {
  MAXIMUM_GROUP_COUNT,
  MAXIMUM_GROUP_ID,
  MAXIMUM_LENGTHS,
  PAYLOAD_KEYS,
};
