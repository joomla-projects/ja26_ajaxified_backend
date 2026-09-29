/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import AutosaveCanonicalActionCoordinator, {
  SUBMISSION_COMPLETE_EVENT,
  SUBMISSION_ERROR_EVENT,
  normalizeTaskPolicy,
} from '../../../../media_source/com_autosave/js/canonical-action-coordinator.es6.js';
import SubmissionEligibility from '../../../../media_source/system/js/submission-eligibility.es6.js';

const TASK_POLICY = Object.freeze({
  'article.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'article.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'article.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'article.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'article.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const createCoordinator = (options) => new AutosaveCanonicalActionCoordinator({
  taskPolicy: TASK_POLICY,
  submitForm: (task, form) => {
    form.elements.task.value = task;
    form.requestSubmit();
  },
  ...options,
});

const settle = async (turns = 12) => {
  for (let index = 0; index < turns; index += 1) {
    await Promise.resolve();
  }
};

class FakeInput {
  constructor(name, value = '') {
    this.name = name;
    this.value = value;
    this.tagName = 'INPUT';
    this.type = name === 'task' ? 'hidden' : 'text';
    this.dataset = {};
  }

  remove() {
    this.form?.removeInput(this);
  }
}

class FakeForm extends EventTarget {
  constructor(task) {
    super();
    this.dataset = {};
    this.attributes = new Map();
    this.listenerCounts = new Map();
    this.requestSubmitCalls = 0;
    this.inputs = [new FakeInput('task', task)];
    this.inputs[0].form = this;
    this.ownerDocument = {
      createElement: () => new FakeInput(''),
    };
    this.refreshElements();
  }

  requestSubmit() {
    this.requestSubmitCalls += 1;
    this.dispatchEvent(new Event('submit', { cancelable: true }));
  }

  addEventListener(type, callback, options) {
    super.addEventListener(type, callback, options);
    this.listenerCounts.set(type, (this.listenerCounts.get(type) || 0) + 1);
  }

  removeEventListener(type, callback, options) {
    super.removeEventListener(type, callback, options);
    this.listenerCounts.set(type, Math.max(0, (this.listenerCounts.get(type) || 0) - 1));
  }

  appendChild(input) {
    input.form = this;
    this.inputs.push(input);
    this.refreshElements();
  }

  removeInput(input) {
    this.inputs = this.inputs.filter((candidate) => candidate !== input);
    this.refreshElements();
  }

  refreshElements() {
    this.elements = this.inputs;
    this.elements.task = this.inputs.find((input) => input.name === 'task') || null;
    this.elements.namedItem = (name) => this.inputs.find((input) => input.name === name) || null;
  }

  querySelectorAll(selector) {
    if (selector === '[data-autosave-canonical-metadata="true"]') {
      return this.inputs.filter((input) => input.dataset.autosaveCanonicalMetadata === 'true');
    }

    return [];
  }

  setAttribute(name, value) {
    this.attributes.set(name, value);
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }
}

const successfulOutcome = {
  operation_id: 'operation-1',
  intent: 'apply',
  outcome: 'successful',
  final_base_revision: 'base-2',
};

const createRuntime = (overrides = {}) => ({
  state: { recoveryCandidate: null },
  prepareCalls: [],
  queryCalls: [],
  reconcileCalls: [],
  unconfirmedCalls: [],
  cancelPreparationCalls: 0,
  recoveryFocusCalls: 0,
  async prepareCanonicalAction(intent) {
    this.prepareCalls.push(intent);

    return { operationId: 'operation-1', intent, submittedChangeSequence: 1 };
  },
  async queryCanonicalActionOutcome(operationId) {
    this.queryCalls.push(operationId);

    return successfulOutcome;
  },
  reconcileCanonicalAction(outcome) {
    this.reconcileCalls.push(outcome);

    return true;
  },
  markCanonicalActionOutcomeUnconfirmed(operationId, error) {
    this.unconfirmedCalls.push({ operationId, error });

    return true;
  },
  async prepareForCancel() {
    this.cancelPreparationCalls += 1;

    return true;
  },
  requestRecoveryResolution() {
    this.recoveryFocusCalls += 1;

    return true;
  },
  ...overrides,
});

test('Apply prepares metadata before exactly one existing Ajax submission and reconciles outcome', async () => {
  const form = new FakeForm('article.apply');
  const runtime = createRuntime();
  const coordinator = createCoordinator({ form, runtime }).start();
  let submissions = 0;

  form.addEventListener('submit', () => {
    submissions += 1;
    assert.equal(form.elements.namedItem('autosave_operation_id').value, 'operation-1');
    form.dispatchEvent(new CustomEvent(SUBMISSION_COMPLETE_EVENT, {
      detail: { task: 'article.apply' },
    }));
  });

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.deepEqual(runtime.prepareCalls, ['apply']);
  assert.equal(submissions, 1);
  assert.deepEqual(runtime.queryCalls, ['operation-1']);
  assert.deepEqual(runtime.reconcileCalls, [successfulOutcome]);
  assert.equal(form.elements.namedItem('autosave_operation_id'), null);
  assert.equal(form.attributes.has('aria-busy'), false);
  coordinator.destroy();
});

test('task policy is copied, validated and prototype names remain ordinary passthrough tasks', () => {
  const mutable = {
    'record.apply': { intent: 'apply', transport: 'ajax', canonical: true },
  };
  const normalized = normalizeTaskPolicy(mutable);
  mutable['record.apply'].intent = 'changed';

  assert.deepEqual(normalized.get('record.apply'), {
    intent: 'apply', transport: 'ajax', canonical: true,
  });
  assert.throws(
    () => normalizeTaskPolicy({ broken: { intent: '', transport: 'beacon', canonical: 'yes' } }),
    TypeError,
  );

  const form = new FakeForm('toString');
  const runtime = createRuntime();
  const coordinator = createCoordinator({ form, runtime }).start();
  const event = new Event('submit', { cancelable: true });
  form.dispatchEvent(event);

  assert.equal(event.defaultPrevented, false);
  assert.deepEqual(runtime.prepareCalls, []);
  coordinator.destroy();
});

test('Unresolved recovery blocks canonical actions without preparing or submitting', async () => {
  const form = new FakeForm('article.save');
  const runtime = createRuntime({ state: { recoveryCandidate: { classification: 'current' } } });
  const coordinator = createCoordinator({ form, runtime }).start();
  let submissions = 0;
  form.addEventListener('submit', () => { submissions += 1; });

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.equal(runtime.recoveryFocusCalls, 1);
  assert.deepEqual(runtime.prepareCalls, []);
  assert.equal(submissions, 0);
  coordinator.destroy();
});

test('Unknown tasks retain existing Joomla behavior', () => {
  const form = new FakeForm('article.extensionTask');
  const runtime = createRuntime();
  const coordinator = createCoordinator({ form, runtime }).start();
  const event = new Event('submit', { cancelable: true });

  form.dispatchEvent(event);

  assert.equal(event.defaultPrevented, false);
  assert.deepEqual(runtime.prepareCalls, []);
  coordinator.destroy();
});

test('Save as Copy resumes once with a form-local native transport override', async () => {
  const form = new FakeForm('article.save2copy');
  const runtime = createRuntime();
  const coordinator = createCoordinator({ form, runtime }).start();
  let override;

  form.addEventListener('submit', () => {
    override = form.dataset.joomlaSubmissionTransport;
  });
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.deepEqual(runtime.prepareCalls, ['save-copy']);
  assert.equal(override, 'native');
  assert.equal(form.requestSubmitCalls, 1);
  coordinator.destroy();
});

test('the production continuation delegates to Joomla.submitform instead of replacing it', async (t) => {
  const previousJoomla = globalThis.Joomla;
  const form = new FakeForm('article.save');
  const runtime = createRuntime();
  const calls = [];

  globalThis.Joomla = {
    submitform: (task, submittedForm) => {
      calls.push({ task, form: submittedForm });
      submittedForm.dispatchEvent(new Event('submit', { cancelable: true }));
    },
  };
  t.after(() => { globalThis.Joomla = previousJoomla; });

  form.requestSubmit = () => assert.fail('The coordinator must not replace Joomla.submitform.');
  const coordinator = new AutosaveCanonicalActionCoordinator({
    form,
    runtime,
    taskPolicy: TASK_POLICY,
  }).start();

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.equal(calls.length, 1);
  assert.equal(calls[0].task, 'article.save');
  assert.strictEqual(calls[0].form, form);
  coordinator.destroy();
});

test('all canonical tasks resume through Joomla with their original transport semantics', async (t) => {
  const previousWindow = globalThis.window;
  const previousJoomla = globalThis.Joomla;

  globalThis.window = { isSecureContext: true };
  globalThis.Joomla = {
    getOptions: () => ({
      apply: 'ajax',
      save2copy: 'ajax',
      save: 'native',
      save2new: 'native',
      cancel: 'native',
    }),
  };

  t.after(() => {
    globalThis.window = previousWindow;
    globalThis.Joomla = previousJoomla;
  });

  const cases = [
    ['article.apply', 'apply', true],
    ['article.save', 'save', false],
    ['article.save2new', 'save2new', false],
    ['article.save2copy', 'save2copy', false],
    ['article.cancel', 'cancel', false],
  ];

  for (const [task, action, expectedAjax] of cases) {
    await t.test(task, async () => {
      const form = new FakeForm(task);
      const runtime = createRuntime();
      const resumed = [];
      const decisions = [];
      const coordinator = createCoordinator({
        form,
        runtime,
        submitForm: (resumedTask, resumedForm) => {
          resumed.push({ task: resumedTask, form: resumedForm });
          resumedForm.elements.task.value = resumedTask;
          resumedForm.requestSubmit();
        },
      }).start();

      form.addEventListener('submit', () => {
        decisions.push(SubmissionEligibility.evaluate({ form, action }));

        if (expectedAjax) {
          form.dispatchEvent(new CustomEvent(SUBMISSION_COMPLETE_EVENT, {
            detail: { task },
          }));
        }
      });

      form.dispatchEvent(new Event('submit', { cancelable: true }));
      await settle();

      assert.equal(resumed.length, 1);
      assert.equal(resumed[0].task, task);
      assert.strictEqual(resumed[0].form, form);
      assert.equal(form.elements.task.value, task);
      assert.equal(decisions.length, 1);
      assert.equal(decisions[0].eligible, expectedAjax);
      assert.equal(form.dataset.joomlaSubmissionTransport, undefined);
      coordinator.destroy();
    });
  }
});

test('preparation failure blocks canonical submission and releases the action lock', async () => {
  const form = new FakeForm('article.apply');
  const failure = Object.assign(new Error('prepare failed'), { code: 'network_failure' });
  const runtime = createRuntime({
    async prepareCanonicalAction(intent) {
      this.prepareCalls.push(intent);
      throw failure;
    },
  });
  const coordinator = createCoordinator({ form, runtime }).start();
  let submissions = 0;
  form.addEventListener('submit', () => { submissions += 1; });

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.deepEqual(runtime.prepareCalls, ['apply']);
  assert.equal(submissions, 0);
  assert.equal(form.attributes.has('aria-busy'), false);
  coordinator.destroy();
});

test('a throwing Joomla continuation is never replayed and resolves only from durable outcome', async () => {
  const form = new FakeForm('article.apply');
  const runtime = createRuntime({
    async queryCanonicalActionOutcome(operationId) {
      this.queryCalls.push(operationId);

      return successfulOutcome;
    },
  });
  let submitCalls = 0;
  const coordinator = createCoordinator({
    form,
    runtime,
    submitForm: () => {
      submitCalls += 1;
      throw new Error('submission continuation failed');
    },
  }).start();

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.equal(submitCalls, 1);
  assert.deepEqual(runtime.queryCalls, ['operation-1']);
  assert.deepEqual(runtime.reconcileCalls, [successfulOutcome]);
  assert.equal(form.listenerCounts.get(SUBMISSION_COMPLETE_EVENT), 0);
  assert.equal(form.listenerCounts.get(SUBMISSION_ERROR_EVENT), 0);
  coordinator.destroy();
});

test('lost Ajax completion never repeats Apply and resolves only through bounded outcome queries', async () => {
  const form = new FakeForm('article.apply');
  const outcomes = [
    { ...successfulOutcome, outcome: 'pending' },
    successfulOutcome,
  ];
  const runtime = createRuntime({
    async queryCanonicalActionOutcome(operationId) {
      this.queryCalls.push(operationId);

      return outcomes.shift();
    },
  });
  let nextTimer = 0;
  const clock = {
    setTimeout(callback) {
      nextTimer += 1;
      queueMicrotask(callback);

      return nextTimer;
    },
    clearTimeout() {},
  };
  const coordinator = createCoordinator({
    form,
    runtime,
    clock,
    outcomeRetryDelay: 1,
  }).start();

  form.addEventListener('submit', () => {
    form.dispatchEvent(new CustomEvent(SUBMISSION_ERROR_EVENT, {
      detail: { task: 'article.apply', uncertain: true },
    }));
  });
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle(24);

  assert.equal(form.requestSubmitCalls, 1);
  assert.deepEqual(runtime.queryCalls, ['operation-1', 'operation-1']);
  assert.deepEqual(runtime.reconcileCalls, [successfulOutcome]);
  coordinator.destroy();
});

test('Ajax completion reconciles definitive failure and unknown outcomes without repeating Apply', async (t) => {
  for (const outcome of ['failed', 'unknown']) {
    await t.test(outcome, async () => {
      const form = new FakeForm('article.apply');
      const result = {
        operation_id: 'operation-1',
        intent: 'apply',
        outcome,
        final_base_revision: null,
        failure_code: outcome === 'failed' ? 'canonical_save_failed' : null,
      };
      const runtime = createRuntime({
        async queryCanonicalActionOutcome(operationId) {
          this.queryCalls.push(operationId);

          return result;
        },
      });
      const coordinator = createCoordinator({ form, runtime }).start();

      form.addEventListener('submit', () => {
        form.dispatchEvent(new CustomEvent(SUBMISSION_COMPLETE_EVENT, {
          detail: { task: 'article.apply' },
        }));
      });
      form.dispatchEvent(new Event('submit', { cancelable: true }));
      await settle();

      assert.equal(form.requestSubmitCalls, 1);
      assert.deepEqual(runtime.queryCalls, ['operation-1']);
      assert.deepEqual(runtime.reconcileCalls, [result]);
      coordinator.destroy();
    });
  }
});

test('bounded pending outcome checks end in explicit unconfirmed state', async () => {
  const form = new FakeForm('article.apply');
  const runtime = createRuntime({
    async queryCanonicalActionOutcome(operationId) {
      this.queryCalls.push(operationId);

      return { ...successfulOutcome, outcome: 'pending' };
    },
  });
  const clock = {
    setTimeout(callback) {
      queueMicrotask(callback);

      return 1;
    },
    clearTimeout() {},
  };
  const coordinator = createCoordinator({
    form,
    runtime,
    clock,
    maximumOutcomeAttempts: 2,
    outcomeRetryDelay: 1,
  }).start();

  form.addEventListener('submit', () => {
    form.dispatchEvent(new CustomEvent(SUBMISSION_COMPLETE_EVENT, {
      detail: { task: 'article.apply' },
    }));
  });
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle(24);

  assert.equal(form.requestSubmitCalls, 1);
  assert.deepEqual(runtime.queryCalls, ['operation-1', 'operation-1']);
  assert.deepEqual(runtime.reconcileCalls, []);
  assert.equal(runtime.unconfirmedCalls.length, 1);
  assert.equal(runtime.unconfirmedCalls[0].operationId, 'operation-1');
  coordinator.destroy();
});

test('Cancel waits for runtime preparation and Stay keeps the page when it cannot be confirmed', async () => {
  const form = new FakeForm('article.cancel');
  const runtime = createRuntime({ prepareForCancel: async () => false });
  let confirmations = 0;
  const coordinator = createCoordinator({
    form,
    runtime,
    confirmLeave: async () => {
      confirmations += 1;

      return false;
    },
  }).start();
  let submissions = 0;
  form.addEventListener('submit', () => { submissions += 1; });

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.equal(confirmations, 1);
  assert.equal(submissions, 0);
  coordinator.destroy();
});

test('Cancel proceeds once after preparation or explicit Leave Anyway', async (t) => {
  await t.test('prepared leave', async () => {
    const form = new FakeForm('article.cancel');
    const runtime = createRuntime();
    const coordinator = createCoordinator({ form, runtime }).start();

    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();

    assert.equal(runtime.cancelPreparationCalls, 1);
    assert.equal(form.requestSubmitCalls, 1);
    coordinator.destroy();
  });

  await t.test('Leave Anyway', async () => {
    const form = new FakeForm('article.cancel');
    const runtime = createRuntime({ prepareForCancel: async () => false });
    const coordinator = createCoordinator({
      form,
      runtime,
      confirmLeave: async () => true,
    }).start();

    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();

    assert.equal(form.requestSubmitCalls, 1);
    coordinator.destroy();
  });
});

test('Action locking and destruction prevent duplicate or stale coordination', async () => {
  const form = new FakeForm('article.apply');
  let resolvePreparation;
  const runtime = createRuntime({
    prepareCanonicalAction: () => new Promise((resolve) => { resolvePreparation = resolve; }),
  });
  const coordinator = createCoordinator({ form, runtime }).start();

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  coordinator.destroy();
  resolvePreparation({ operationId: 'operation-1', intent: 'apply' });
  await settle();

  assert.equal(form.attributes.has('aria-busy'), false);
  assert.equal(form.listenerCounts.get('submit'), 0);
});

test('a coordinator cannot resume submission on a disconnected form', async () => {
  const form = new FakeForm('article.apply');
  form.isConnected = true;
  let resolvePreparation;
  const runtime = createRuntime({
    prepareCanonicalAction: () => new Promise((resolve) => { resolvePreparation = resolve; }),
  });
  const coordinator = createCoordinator({ form, runtime }).start();

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  form.isConnected = false;
  resolvePreparation({ operationId: 'operation-1', intent: 'apply' });
  await settle();

  assert.equal(form.requestSubmitCalls, 0);
  coordinator.destroy();
});

test('preparation keeps the form editable and submits the validated snapshot without losing later edits', async () => {
  const form = new FakeForm('article.apply');
  const title = new FakeInput('jform[title]', 'Submitted title');
  form.appendChild(title);
  let resolvePreparation;
  const runtime = createRuntime({
    prepareCanonicalAction: () => new Promise((resolve) => { resolvePreparation = resolve; }),
  });
  const coordinator = createCoordinator({ form, runtime }).start();
  let titleDuringSubmission;

  form.addEventListener('submit', () => {
    titleDuringSubmission = title.value;
    form.dispatchEvent(new CustomEvent(SUBMISSION_COMPLETE_EVENT, {
      detail: { task: 'article.apply' },
    }));
  });
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  title.value = 'Later local title';
  resolvePreparation({ operationId: 'operation-1', intent: 'apply' });
  await settle();

  assert.equal(form.attributes.has('data-autosave-preparing'), false);
  assert.equal(titleDuringSubmission, 'Submitted title');
  assert.equal(title.value, 'Later local title');
  coordinator.destroy();
});

test('destruction removes pending Ajax outcome listeners and retry timers', async () => {
  const form = new FakeForm('article.apply');
  const runtime = createRuntime();
  const pendingTimers = new Map();
  let nextTimer = 0;
  const clock = {
    setTimeout(callback) {
      nextTimer += 1;
      pendingTimers.set(nextTimer, callback);

      return nextTimer;
    },
    clearTimeout(timer) {
      pendingTimers.delete(timer);
    },
  };
  const coordinator = createCoordinator({ form, runtime, clock }).start();

  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();
  assert.equal(form.listenerCounts.get(SUBMISSION_COMPLETE_EVENT), 1);
  assert.equal(form.listenerCounts.get(SUBMISSION_ERROR_EVENT), 1);

  coordinator.destroy();
  await settle();

  assert.equal(form.listenerCounts.get(SUBMISSION_COMPLETE_EVENT), 0);
  assert.equal(form.listenerCounts.get(SUBMISSION_ERROR_EVENT), 0);
  assert.equal(pendingTimers.size, 0);
  assert.deepEqual(runtime.queryCalls, []);
  assert.deepEqual(runtime.reconcileCalls, []);
});

test('pre-existing operation fields are replaced for submission and cleared afterward', async () => {
  const form = new FakeForm('article.apply');
  const operation = new FakeInput('autosave_operation_id', 'stale-operation');
  const intent = new FakeInput('autosave_operation_intent', 'stale-intent');
  form.appendChild(operation);
  form.appendChild(intent);
  const runtime = createRuntime();
  const coordinator = createCoordinator({ form, runtime }).start();

  form.addEventListener('submit', () => {
    assert.equal(operation.value, 'operation-1');
    assert.equal(intent.value, 'apply');
    form.dispatchEvent(new CustomEvent(SUBMISSION_COMPLETE_EVENT, {
      detail: { task: 'article.apply' },
    }));
  });
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await settle();

  assert.equal(operation.value, '');
  assert.equal(intent.value, '');
  assert.equal(operation.dataset.autosaveCanonicalMetadata, undefined);
  assert.equal(intent.dataset.autosaveCanonicalMetadata, undefined);
  coordinator.destroy();
});
