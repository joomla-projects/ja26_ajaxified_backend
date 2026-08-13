/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import {
  AUTOSAVE_DRAFT_EVENT,
  AUTOSAVE_RECOVERY_FOCUS_EVENT,
  AUTOSAVE_STATE_EVENT,
} from 'com_autosave.runtime';
import createAutosavePresenter, {
  createAutosaveDateFormatter,
} from '../../../../media_source/com_autosave/js/ui.es6.js';

const STATUSES = [
  'clean',
  'detecting',
  'dirty',
  'waiting-debounce',
  'initializing',
  'preserving',
  'preserved',
  'offline',
  'retry-waiting',
  'paused',
  'authentication-required',
  'conflict',
  'terminal',
  'error',
  'recovery-required',
  'recovery-applying',
  'recovery-discarding',
  'canonical-preparing',
  'canonical-submitting',
  'canonical-outcome-pending',
  'canonical-failed',
  'canonical-prepare-failed',
  'canonical-outcome-unknown',
  'destroyed',
  'unknown',
];

const selectorAttribute = (selector) => {
  const match = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);

  return match ? { name: match[1], value: match[2] } : null;
};

class FakeDocument {
  constructor() {
    this.activeElement = null;
  }
}

class FakeElement extends EventTarget {
  constructor(document, attributes = {}) {
    super();
    this.ownerDocument = document;
    this.attributes = new Map(Object.entries(attributes));
    this.children = [];
    this.parentElement = null;
    this.hidden = true;
    this.disabled = false;
    this.isConnected = true;
    this.textContent = '';
    this.dateTime = '';
  }

  append(...children) {
    children.forEach((child) => {
      child.parentElement = this;
      this.children.push(child);
    });
  }

  remove() {
    if (this.parentElement) {
      this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
      this.parentElement = null;
    }

    this.isConnected = false;
  }

  hasAttribute(name) {
    return this.attributes.has(name);
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  matches(selector) {
    const attribute = selectorAttribute(selector);

    if (!attribute || !this.hasAttribute(attribute.name)) {
      return false;
    }

    return attribute.value === undefined || this.getAttribute(attribute.name) === attribute.value;
  }

  querySelectorAll(selector) {
    const matches = [];

    const visit = (element) => {
      element.children.forEach((child) => {
        if (child.matches(selector)) {
          matches.push(child);
        }

        visit(child);
      });
    };

    visit(this);

    return matches;
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }

  contains(element) {
    if (element === this) {
      return true;
    }

    return this.children.some((child) => child.contains(element));
  }

  focus() {
    this.ownerDocument.activeElement = this;
  }

  click() {
    if (!this.disabled) {
      this.dispatchEvent(new Event('click'));
    }
  }
}

const element = (document, name, value) => new FakeElement(document, {
  [name]: value ?? '',
});

const createMount = () => {
  const document = new FakeDocument();
  const mount = new FakeElement(document);
  const statusMount = element(document, 'data-joomla-autosave-status-ui');
  const recoveryMount = element(document, 'data-joomla-autosave-recovery-ui');
  const status = element(document, 'data-autosave-status');
  const alert = element(document, 'data-autosave-alert');
  const recovery = element(document, 'data-autosave-recovery');
  const recoveryTimeContainer = element(document, 'data-autosave-recovery-time-container');
  const recoveryTime = element(document, 'data-autosave-recovery-time');
  const busy = element(document, 'data-autosave-busy');
  const localEdits = element(document, 'data-autosave-recovery-local-edits');

  status.append(...STATUSES.map((state) => element(document, 'data-autosave-state', state)));
  alert.append(...[
    'authentication-required',
    'conflict',
    'terminal',
    'error',
    'recovery-required',
  ].map((state) => element(document, 'data-autosave-alert-state', state)));
  recovery.append(
    element(document, 'data-autosave-recovery-current'),
    element(document, 'data-autosave-recovery-stale'),
    element(document, 'data-autosave-recovery-unknown'),
    localEdits,
    recoveryTimeContainer,
    busy,
  );

  const buttons = Object.fromEntries(['restore', 'keep-current', 'discard', 'retry'].map(
    (action) => [action, element(document, 'data-autosave-action', action)],
  ));
  buttons.discard.setAttribute('data-autosave-confirm-title', 'Confirm discard');
  buttons.discard.setAttribute('data-autosave-confirm-message', 'Discard the draft?');
  recovery.append(buttons.restore, buttons['keep-current'], buttons.discard);
  recoveryTimeContainer.append(recoveryTime);
  statusMount.append(status, buttons.retry);
  recoveryMount.append(alert, recovery);
  mount.append(statusMount, recoveryMount);

  return {
    alert,
    buttons,
    busy,
    document,
    localEdits,
    mount,
    recovery,
    recoveryMount,
    recoveryTime,
    recoveryTimeContainer,
    status,
    statusMount,
  };
};

const defaultState = () => ({
  lifecycle: 'active',
  status: 'clean',
  dirty: false,
  lastSuccessfulAt: null,
  error: null,
  recoveryCandidate: null,
});

const stateEvent = (type, detail) => {
  const event = new Event(type);
  Object.defineProperty(event, 'detail', { value: detail });

  return event;
};

class FakeRuntime {
  constructor(eventTarget, state = defaultState()) {
    this.eventTarget = eventTarget;
    this.currentState = state;
    this.calls = {
      discard: 0,
      keepCurrent: 0,
      restore: 0,
      retry: 0,
    };
    this.restoreImplementation = null;
    this.discardImplementation = null;
  }

  get state() {
    return this.currentState;
  }

  update(patch, eventName = AUTOSAVE_STATE_EVENT) {
    this.currentState = { ...this.currentState, ...patch };
    this.eventTarget.dispatchEvent(stateEvent(eventName, this.currentState));
  }

  async restoreDetectedDraft() {
    this.calls.restore += 1;

    if (this.restoreImplementation) {
      return this.restoreImplementation();
    }

    return false;
  }

  async discardDetectedDraft() {
    this.calls.discard += 1;

    if (this.discardImplementation) {
      return this.discardImplementation();
    }

    return false;
  }

  keepCurrent() {
    this.calls.keepCurrent += 1;
    this.update({ recoveryCandidate: null, status: 'clean' });

    return true;
  }

  retry() {
    this.calls.retry += 1;
    this.update({ error: null, status: 'retry-waiting' });

    return true;
  }
}

const candidate = (classification = 'current') => ({
  classification,
  localEdits: false,
  updatedAt: '2026-08-04T10:30:00Z',
});

const createFixture = ({
  state = defaultState(),
  confirmDiscard = async () => true,
  formatTimestamp = () => ({ dateTime: '2026-08-04T10:30:00Z', text: '4 Aug 2026, 10:30' }),
} = {}) => {
  const dom = createMount();
  const eventTarget = new EventTarget();
  const runtime = new FakeRuntime(eventTarget, state);
  const presenter = createAutosavePresenter({
    confirmDiscard,
    eventTarget,
    formatTimestamp,
    recoveryMount: dom.recoveryMount,
    runtime,
    statusMount: dom.statusMount,
  });

  return {
    ...dom,
    eventTarget,
    presenter,
    runtime,
  };
};

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('valid construction renders runtime.state immediately and malformed layouts fail safely', () => {
  const fixture = createFixture({ state: { ...defaultState(), status: 'dirty' } });
  assert.ok(fixture.presenter);
  assert.equal(fixture.statusMount.hidden, false);
  assert.equal(fixture.mount.querySelector('[data-autosave-state="dirty"]').hidden, false);

  const malformed = createMount();
  malformed.statusMount.querySelector('[data-autosave-status]').remove();
  const runtime = new FakeRuntime(new EventTarget());
  const presenter = createAutosavePresenter({
    eventTarget: runtime.eventTarget,
    recoveryMount: malformed.recoveryMount,
    statusMount: malformed.statusMount,
    runtime,
  });
  assert.ok(presenter);
  assert.equal(presenter.statusUi, null);
  assert.ok(presenter.recoveryUi);
  assert.equal(runtime.calls.restore, 0);

  const optional = createMount();
  optional.localEdits.remove();
  const optionalRuntime = new FakeRuntime(new EventTarget());
  assert.ok(createAutosavePresenter({
    eventTarget: optionalRuntime.eventTarget,
    recoveryMount: optional.recoveryMount,
    statusMount: optional.statusMount,
    runtime: optionalRuntime,
  }));

  optional.statusMount.querySelector('[data-autosave-status]').remove();
  optional.recoveryMount.querySelector('[data-autosave-recovery]').remove();
  assert.equal(createAutosavePresenter({
    eventTarget: optionalRuntime.eventTarget,
    recoveryMount: optional.recoveryMount,
    statusMount: optional.statusMount,
    runtime: optionalRuntime,
  }), null);
});

test('canonical recovery blocking focuses the generic recovery region without exposing content', () => {
  const fixture = createFixture({
    state: {
      ...defaultState(),
      status: 'recovery-required',
      recoveryCandidate: candidate(),
    },
  });

  fixture.eventTarget.dispatchEvent(stateEvent(AUTOSAVE_RECOVERY_FOCUS_EVENT, {
    context: 'com_content.article',
    targetId: '42',
  }));

  assert.equal(fixture.document.activeElement, fixture.recovery);
  assert.equal(fixture.recovery.hasAttribute('tabindex'), false);
});

test('status and recovery layouts degrade independently', () => {
  const statusOnly = createMount();
  const statusRuntime = new FakeRuntime(new EventTarget(), {
    ...defaultState(),
    status: 'preserved',
  });
  const statusPresenter = createAutosavePresenter({
    eventTarget: statusRuntime.eventTarget,
    runtime: statusRuntime,
    statusMount: statusOnly.statusMount,
  });
  assert.ok(statusPresenter);
  assert.equal(statusOnly.statusMount.hidden, false);
  assert.equal(
    statusOnly.statusMount.querySelector('[data-autosave-state="preserved"]').hidden,
    false,
  );

  const recoveryOnly = createMount();
  const recoveryRuntime = new FakeRuntime(new EventTarget(), {
    ...defaultState(),
    recoveryCandidate: candidate(),
    status: 'recovery-required',
  });
  const recoveryPresenter = createAutosavePresenter({
    eventTarget: recoveryRuntime.eventTarget,
    recoveryMount: recoveryOnly.recoveryMount,
    runtime: recoveryRuntime,
  });
  assert.ok(recoveryPresenter);
  assert.equal(recoveryOnly.recoveryMount.hidden, false);
  assert.equal(recoveryOnly.recovery.hidden, false);

  assert.equal(createAutosavePresenter({
    eventTarget: new EventTarget(),
    runtime: new FakeRuntime(new EventTarget()),
  }), null);
});

test('every public runtime status has an explicit conservative presentation', () => {
  const fixture = createFixture();

  STATUSES.forEach((status) => {
    fixture.runtime.update({ status });
    const visible = fixture.mount.querySelectorAll('[data-autosave-state]')
      .filter((node) => !node.hidden);

    if (status === 'destroyed') {
      assert.equal(fixture.statusMount.hidden, true);
    } else {
      assert.equal(fixture.statusMount.hidden, false);
      assert.equal(visible.length, 1);
      assert.equal(visible[0].getAttribute('data-autosave-state'), status);
    }
  });

  fixture.runtime.update({ status: 'future-state' });
  assert.equal(fixture.mount.querySelector('[data-autosave-state="unknown"]').hidden, false);
});

test('busy and urgent states use their separate accessible regions without duplicate routing', () => {
  const fixture = createFixture();
  fixture.runtime.update({ status: 'preserving' });
  assert.equal(fixture.status.getAttribute('aria-busy'), 'true');

  fixture.runtime.update({ status: 'authentication-required' });
  assert.equal(fixture.recoveryMount.hidden, false);
  assert.equal(
    fixture.alert.querySelector('[data-autosave-alert-state="authentication-required"]').hidden,
    false,
  );
  fixture.runtime.update({ status: 'authentication-required' }, AUTOSAVE_DRAFT_EVENT);
  assert.equal(
    fixture.alert.querySelectorAll('[data-autosave-alert-state]').filter((node) => !node.hidden).length,
    1,
  );
});

test('recovery visibility and network actions follow authoritative runtime state', () => {
  const fixture = createFixture({
    state: {
      ...defaultState(),
      recoveryCandidate: candidate('current'),
      status: 'recovery-required',
    },
  });
  assert.equal(fixture.recovery.hidden, false);
  assert.equal(fixture.mount.querySelector('[data-autosave-recovery-current]').hidden, false);

  ['offline', 'authentication-required', 'error', 'paused'].forEach((status) => {
    fixture.runtime.update({ status });
    assert.equal(fixture.recovery.hidden, false);
  });

  fixture.runtime.update({ status: 'offline' });
  assert.equal(fixture.buttons.restore.disabled, true);
  assert.equal(fixture.buttons.discard.disabled, true);
  assert.equal(fixture.buttons['keep-current'].disabled, false);

  fixture.runtime.update({ status: 'recovery-required' });
  assert.equal(fixture.buttons.restore.disabled, false);
  assert.equal(fixture.buttons.discard.disabled, false);
  assert.equal(fixture.buttons['keep-current'].disabled, false);

  fixture.runtime.update({ recoveryCandidate: candidate('stale') });
  assert.equal(fixture.mount.querySelector('[data-autosave-recovery-stale]').hidden, false);
  fixture.runtime.update({ recoveryCandidate: candidate('invalid') });
  assert.equal(fixture.mount.querySelector('[data-autosave-recovery-unknown]').hidden, false);
  fixture.runtime.update({ recoveryCandidate: null });
  assert.equal(fixture.recovery.hidden, true);
});

test('only recovery timestamps are validated, formatted and exposed', () => {
  const formatter = createAutosaveDateFormatter('en-GB', 'UTC');
  const valid = formatter('2026-08-04T10:30:00Z');
  assert.equal(valid.dateTime, '2026-08-04T10:30:00Z');
  assert.equal(typeof valid.text, 'string');
  assert.equal(formatter('not-a-time'), null);
  assert.ok(createAutosaveDateFormatter('invalid_locale_', 'Mars/Phobos')(
    '2026-08-04T10:30:00Z',
  ));

  const configuredZone = createAutosaveDateFormatter('en-GB', 'Asia/Kolkata')(
    '2026-08-04T17:55:00Z',
  );
  assert.equal(configuredZone.dateTime, '2026-08-04T17:55:00Z');
  assert.match(configuredZone.text, /23:25/);

  const fixture = createFixture({
    formatTimestamp: createAutosaveDateFormatter('en-GB', 'UTC'),
    state: {
      ...defaultState(),
      lastSuccessfulAt: 1785839400000,
      recoveryCandidate: candidate(),
      status: 'preserved',
    },
  });
  assert.equal(fixture.statusMount.querySelector('time'), null);
  assert.equal(fixture.statusMount.querySelector('[data-autosave-time]'), null);
  assert.equal(fixture.recoveryTimeContainer.hidden, false);
  assert.equal(fixture.recoveryTime.getAttribute('datetime'), '2026-08-04T10:30:00Z');
  assert.equal(fixture.recoveryTime.textContent, '4 Aug 2026, 10:30');

  fixture.runtime.update({
    lastSuccessfulAt: '2099-01-01T00:00:00Z',
    recoveryCandidate: { ...candidate(), updatedAt: 'invalid' },
  });
  assert.equal(fixture.recoveryTimeContainer.hidden, true);
  assert.equal(fixture.recoveryTime.textContent, '');

  fixture.presenter.formatTimestamp = () => null;
  fixture.runtime.update({ recoveryCandidate: candidate() });
  assert.equal(fixture.recoveryTimeContainer.hidden, true);

  const failedFormatter = createFixture({
    formatTimestamp: () => {
      throw new Error('Formatter failed');
    },
    state: {
      ...defaultState(),
      recoveryCandidate: candidate(),
      status: 'preserved',
    },
  });
  assert.equal(failedFormatter.recoveryTimeContainer.hidden, true);
});

test('generic layouts preserve placement, accessibility, contrast and timestamp boundaries', async () => {
  const [statusLayout, recoveryLayout, articleLayout, articleView, viewConfigurator, language] = await Promise.all([
    readFile('layouts/joomla/autosave/status.php', 'utf8'),
    readFile('layouts/joomla/autosave/recovery.php', 'utf8'),
    readFile('administrator/components/com_content/tmpl/article/edit.php', 'utf8'),
    readFile('administrator/components/com_content/src/View/Article/HtmlView.php', 'utf8'),
    readFile('libraries/src/Autosave/AutosaveViewConfigurator.php', 'utf8'),
    readFile('administrator/language/en-GB/com_autosave.ini', 'utf8'),
  ]);

  assert.match(statusLayout, /role="status"/);
  assert.match(statusLayout, /aria-live="polite"/);
  assert.match(statusLayout, /aria-atomic="true"/);
  assert.doesNotMatch(statusLayout, /<time|data-autosave-time|alert-warning/);
  assert.match(language, /^COM_AUTOSAVE_STATUS_PRESERVED="Draft saved\."$/m);
  assert.doesNotMatch(language, /^COM_AUTOSAVE_TIME_AT=/m);
  assert.match(recoveryLayout, /class="mb-3" data-joomla-autosave-recovery-ui/);
  assert.match(recoveryLayout, /class="alert alert-warning mb-0"/);
  assert.match(recoveryLayout, /<time data-autosave-recovery-time>/);
  assert.doesNotMatch(recoveryLayout, /text-muted|style=|#[0-9a-f]{3,8}/i);
  assert.match(
    articleLayout,
    /d-flex flex-wrap justify-content-between align-items-center gap-2[\s\S]*getLabel\('articletext'\)[\s\S]*joomla\.autosave\.status/,
  );
  assert.ok(
    articleLayout.indexOf('joomla.autosave.recovery')
      < articleLayout.indexOf("getLabel('articletext')"),
  );
  assert.ok(
    articleLayout.indexOf("getLabel('articletext')")
      < articleLayout.indexOf("getInput('articletext')"),
  );
  assert.match(
    viewConfigurator,
    /getParam\([\s\S]*'timezone',[\s\S]*\$this->application->get\('offset', 'UTC'\)/,
  );
  assert.match(articleView, /new AutosaveViewConfigurator/);
});

test('Restore calls only the runtime, prevents duplicate actions and follows authoritative state', async () => {
  const fixture = createFixture({
    state: {
      ...defaultState(),
      recoveryCandidate: candidate(),
      status: 'recovery-required',
    },
  });
  let release;
  fixture.runtime.restoreImplementation = () => new Promise((resolve) => {
    release = () => {
      fixture.runtime.update({ recoveryCandidate: null, status: 'preserved' });
      resolve(true);
    };
  });
  fixture.buttons.restore.focus();
  fixture.buttons.restore.click();
  fixture.buttons.restore.click();
  await flush();
  assert.equal(fixture.runtime.calls.restore, 1);
  assert.equal(fixture.recovery.getAttribute('aria-busy'), 'true');
  assert.equal(fixture.buttons.restore.disabled, true);

  release();
  await flush();
  assert.equal(fixture.recovery.hidden, true);
  assert.strictEqual(fixture.document.activeElement, fixture.status);
});

test('failed Restore and Discard keep the recovery candidate and focus valid', async () => {
  const fixture = createFixture({
    state: {
      ...defaultState(),
      recoveryCandidate: candidate(),
      status: 'recovery-required',
    },
  });
  fixture.runtime.restoreImplementation = async () => {
    fixture.runtime.update({ status: 'offline' });

    return false;
  };
  fixture.buttons.restore.focus();
  fixture.buttons.restore.click();
  await flush();
  assert.equal(fixture.recovery.hidden, false);
  assert.strictEqual(fixture.document.activeElement, fixture.buttons.restore);

  fixture.runtime.update({ status: 'recovery-required' });
  fixture.runtime.discardImplementation = async () => {
    fixture.runtime.update({ status: 'error' });

    return false;
  };
  fixture.buttons.discard.click();
  await flush();
  await flush();
  assert.equal(fixture.runtime.calls.discard, 1);
  assert.equal(fixture.recovery.hidden, false);
});

test('Continue Without Restoring is synchronous and does not use a busy state', () => {
  const fixture = createFixture({
    state: {
      ...defaultState(),
      recoveryCandidate: candidate(),
      status: 'recovery-required',
    },
  });
  fixture.buttons['keep-current'].focus();
  fixture.buttons['keep-current'].click();
  assert.equal(fixture.runtime.calls.keepCurrent, 1);
  assert.equal(fixture.recovery.hidden, true);
  assert.equal(fixture.busy.hidden, true);
  assert.strictEqual(fixture.document.activeElement, fixture.status);
});

test('Discard requires confirmation and cancellation makes no runtime call', async () => {
  let confirmed = false;
  const fixture = createFixture({
    confirmDiscard: async (message, title) => {
      assert.equal(message, 'Discard the draft?');
      assert.equal(title, 'Confirm discard');

      return confirmed;
    },
    state: {
      ...defaultState(),
      recoveryCandidate: candidate(),
      status: 'recovery-required',
    },
  });
  fixture.buttons.discard.click();
  await flush();
  assert.equal(fixture.runtime.calls.discard, 0);

  confirmed = true;
  fixture.runtime.discardImplementation = async () => {
    fixture.runtime.update({ recoveryCandidate: null, status: 'clean' });

    return true;
  };
  fixture.buttons.discard.click();
  fixture.buttons.discard.click();
  await flush();
  await flush();
  assert.equal(fixture.runtime.calls.discard, 1);
  assert.equal(fixture.recovery.hidden, true);
});

test('Retry is narrowly exposed for retryable paused state', () => {
  const fixture = createFixture();
  fixture.runtime.update({ error: { retryable: true }, status: 'paused' });
  assert.equal(fixture.buttons.retry.hidden, false);
  fixture.buttons.retry.click();
  assert.equal(fixture.runtime.calls.retry, 1);

  ['offline', 'conflict', 'terminal', 'error', 'authentication-required'].forEach((status) => {
    fixture.runtime.update({ error: { retryable: true }, status });
    assert.equal(fixture.buttons.retry.hidden, true);
  });
  fixture.runtime.update({ error: { retryable: false }, status: 'paused' });
  assert.equal(fixture.buttons.retry.hidden, true);
});

test('separate EventTargets isolate presenters and destruction invalidates stale completion', async () => {
  const first = createFixture();
  const second = createFixture();
  first.runtime.update({ status: 'dirty' });
  assert.equal(first.mount.querySelector('[data-autosave-state="dirty"]').hidden, false);
  assert.equal(second.mount.querySelector('[data-autosave-state="clean"]').hidden, false);

  first.presenter.destroy();
  first.presenter.destroy();
  first.runtime.update({ status: 'preserved' });
  assert.equal(first.statusMount.hidden, true);
  assert.equal(first.recoveryMount.hidden, true);

  let release;
  const pending = createFixture({
    state: {
      ...defaultState(),
      recoveryCandidate: candidate(),
      status: 'recovery-required',
    },
  });
  pending.runtime.restoreImplementation = () => new Promise((resolve) => {
    release = resolve;
  });
  pending.buttons.restore.click();
  await flush();
  pending.presenter.destroy();
  release(true);
  await flush();
  assert.equal(pending.statusMount.hidden, true);
  assert.equal(pending.recoveryMount.hidden, true);
});
