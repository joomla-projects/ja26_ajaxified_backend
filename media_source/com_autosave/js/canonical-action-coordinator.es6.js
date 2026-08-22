/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

export const SUBMISSION_COMPLETE_EVENT = 'joomla:submission-complete';
export const SUBMISSION_ERROR_EVENT = 'joomla:submission-error';

const OPERATION_FIELD = 'autosave_operation_id';
const INTENT_FIELD = 'autosave_operation_intent';
const UNSUPPORTED_CONTROL_TYPES = new Set(['button', 'file', 'reset', 'submit']);

const defaultConfirmLeave = () => {
  const Dialog = globalThis.customElements?.get?.('joomla-dialog');

  if (!Dialog || typeof Dialog.confirm !== 'function') {
    return Promise.resolve(false);
  }

  return Dialog.confirm(
    globalThis.Joomla?.Text?._?.('COM_AUTOSAVE_CANCEL_DISCARD_FAILED') || '',
    globalThis.Joomla?.Text?._?.('COM_AUTOSAVE_CANCEL_DISCARD_FAILED_TITLE') || '',
  );
};

const defaultClock = {
  setTimeout: (callback, delay) => globalThis.setTimeout(callback, delay),
  clearTimeout: (timer) => globalThis.clearTimeout(timer),
};

const defaultSubmitForm = (task, form) => globalThis.Joomla.submitform(task, form);

const normalizeTaskPolicy = (taskPolicy) => {
  if (!taskPolicy
    || typeof taskPolicy !== 'object'
    || Array.isArray(taskPolicy)) {
    throw new TypeError('The Autosave canonical task policy is invalid.');
  }

  const normalized = new Map();

  Object.entries(taskPolicy).forEach(([task, policy]) => {
    if (task.length === 0
      || !policy
      || typeof policy !== 'object'
      || Array.isArray(policy)
      || typeof policy.intent !== 'string'
      || policy.intent.length === 0
      || !['ajax', 'native'].includes(policy.transport)
      || typeof policy.canonical !== 'boolean') {
      throw new TypeError('The Autosave canonical task policy is invalid.');
    }

    normalized.set(task, Object.freeze({
      intent: policy.intent,
      transport: policy.transport,
      canonical: policy.canonical,
    }));
  });

  return normalized;
};

const readControlState = (control) => {
  if (control.tagName === 'INPUT'
    && (control.type === 'checkbox' || control.type === 'radio')) {
    return Object.freeze({
      checked: control.checked,
      value: control.value,
    });
  }

  if (control.tagName === 'SELECT' && control.multiple) {
    return Object.freeze({
      selected: Object.freeze(Array.from(control.options, (option) => option.selected)),
    });
  }

  return Object.freeze({ value: control.value });
};

const writeControlState = (control, state) => {
  if ('checked' in state) {
    control.checked = state.checked;
    control.value = state.value;

    return;
  }

  if ('selected' in state) {
    Array.from(control.options).forEach((option, index) => {
      option.selected = state.selected[index] === true;
    });

    return;
  }

  control.value = state.value;
};

const captureSubmissionState = (form) => Object.freeze(Array.from(form.elements)
  .filter((control) => control?.name
    && ![OPERATION_FIELD, INTENT_FIELD].includes(control.name)
    && !UNSUPPORTED_CONTROL_TYPES.has(control.type)
    && ('value' in control || control.tagName === 'SELECT'))
  .map((control) => Object.freeze({
    control,
    state: readControlState(control),
  })));

/**
 * Coordinate an owned edit form with the generic Autosave runtime while
 * leaving Joomla's existing canonical submission transport authoritative.
 */
export default class AutosaveCanonicalActionCoordinator {
  constructor({
    form,
    runtime,
    taskPolicy,
    confirmLeave = defaultConfirmLeave,
    clock = defaultClock,
    submitForm = defaultSubmitForm,
    maximumOutcomeAttempts = 5,
    outcomeRetryDelay = 750,
  }) {
    if (!form
      || typeof form.addEventListener !== 'function'
      || typeof form.removeEventListener !== 'function'
      || !runtime
      || typeof runtime.prepareCanonicalAction !== 'function'
      || typeof runtime.queryCanonicalActionOutcome !== 'function'
      || typeof runtime.markCanonicalActionOutcomeUnconfirmed !== 'function'
      || typeof runtime.reconcileCanonicalAction !== 'function'
      || typeof runtime.prepareForCancel !== 'function'
      || typeof runtime.requestRecoveryResolution !== 'function'
      || typeof confirmLeave !== 'function'
      || typeof clock.setTimeout !== 'function'
      || typeof clock.clearTimeout !== 'function'
      || typeof submitForm !== 'function'
      || !Number.isInteger(maximumOutcomeAttempts)
      || maximumOutcomeAttempts < 1
      || !Number.isFinite(outcomeRetryDelay)
      || outcomeRetryDelay < 0) {
      throw new TypeError('The Autosave canonical action coordinator configuration is invalid.');
    }

    this.form = form;
    this.runtime = runtime;
    this.taskPolicy = normalizeTaskPolicy(taskPolicy);
    this.confirmLeave = confirmLeave;
    this.clock = clock;
    this.submitForm = submitForm;
    this.maximumOutcomeAttempts = maximumOutcomeAttempts;
    this.outcomeRetryDelay = outcomeRetryDelay;
    this.started = false;
    this.destroyed = false;
    this.activeAction = null;
    this.bypass = false;
    this.generation = 0;
    this.completionCleanups = new Set();
    this.outcomeRetryWaiters = new Map();
    this.metadataInputs = new Set();
    this.handleSubmit = this.handleSubmit.bind(this);
  }

  start() {
    if (this.started || this.destroyed) {
      return this;
    }

    this.started = true;
    this.form.addEventListener('submit', this.handleSubmit, true);

    return this;
  }

  handleSubmit(event) {
    if (this.destroyed || event.target !== this.form) {
      return;
    }

    if (this.bypass) {
      this.bypass = false;

      return;
    }

    const task = this.form.elements?.task?.value || '';
    const policy = this.taskPolicy.get(task);

    if (!policy) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    if (this.activeAction) {
      return;
    }

    if (policy.canonical && this.runtime.state.recoveryCandidate) {
      this.runtime.requestRecoveryResolution();

      return;
    }

    const token = ++this.generation;
    const submissionState = captureSubmissionState(this.form);
    this.activeAction = {
      token,
      task,
      policy,
      submissionState,
    };
    this.setBusy(true);
    void (policy.canonical
      ? this.coordinateCanonicalAction(token, task, policy, submissionState)
      : this.coordinateCancel(token, task));
  }

  async coordinateCanonicalAction(token, task, policy, submissionState) {
    let prepared = null;

    try {
      prepared = await this.runtime.prepareCanonicalAction(policy.intent);

      if (!this.isCurrent(token)) {
        return;
      }

      this.setOperationMetadata(prepared.operationId, prepared.intent);

      if (policy.transport === 'native') {
        this.resumeSubmission(task, policy.intent === 'save-copy', submissionState);

        return;
      }

      const completion = this.waitForAjaxCompletion(token, task);
      this.resumeSubmission(task, false, submissionState);
      await completion;

      if (!this.isCurrent(token)) {
        return;
      }

      const outcome = await this.resolveOutcome(token, prepared.operationId);

      if (outcome && this.isCurrent(token)) {
        this.runtime.reconcileCanonicalAction(outcome);
      }
    } catch (error) {
      // Preparation errors are already represented by the runtime. Once an
      // operation exists, however, never infer that a throwing continuation
      // means Joomla did not receive the request; resolve only from its durable
      // operation record and preserve the at-most-once submission guarantee.
      if (prepared && this.isCurrent(token)) {
        this.completionCleanups.forEach((cleanup) => cleanup());
        const outcome = await this.resolveOutcome(token, prepared.operationId);

        if (outcome && this.isCurrent(token)) {
          this.runtime.reconcileCanonicalAction(outcome);
        }
      }
    } finally {
      if (this.isCurrent(token)) {
        this.clearOperationMetadata();
        this.setBusy(false);
        this.activeAction = null;
      }
    }
  }

  async coordinateCancel(token, task) {
    try {
      const readyToLeave = await this.runtime.prepareForCancel();

      if (!this.isCurrent(token)) {
        return;
      }

      if (!readyToLeave && !await this.confirmLeave()) {
        return;
      }

      if (this.isCurrent(token)) {
        this.resumeSubmission(task, false);
      }
    } finally {
      if (this.isCurrent(token)) {
        this.setBusy(false);
        this.activeAction = null;
      }
    }
  }

  waitForAjaxCompletion(token, task) {
    return new Promise((resolve) => {
      const form = this.form;
      let listening = true;
      const cleanup = (result = null) => {
        if (!listening) {
          return;
        }

        listening = false;
        form.removeEventListener(SUBMISSION_COMPLETE_EVENT, complete);
        form.removeEventListener(SUBMISSION_ERROR_EVENT, failed);
        this.completionCleanups.delete(cleanup);
        resolve(result);
      };
      const matches = (event) => this.isCurrent(token) && event.detail?.task === task;
      const complete = (event) => {
        if (!matches(event)) {
          return;
        }

        cleanup({ uncertain: false });
      };
      const failed = (event) => {
        if (!matches(event)) {
          return;
        }

        cleanup({ uncertain: true });
      };

      this.completionCleanups.add(cleanup);
      form.addEventListener(SUBMISSION_COMPLETE_EVENT, complete);
      form.addEventListener(SUBMISSION_ERROR_EVENT, failed);
    });
  }

  async resolveOutcome(token, operationId) {
    let lastError = null;

    for (let attempt = 1; attempt <= this.maximumOutcomeAttempts; attempt += 1) {
      if (!this.isCurrent(token)) {
        return null;
      }

      try {
        const outcome = await this.runtime.queryCanonicalActionOutcome(operationId);

        if (outcome.outcome !== 'pending') {
          return outcome;
        }
      } catch (error) {
        lastError = error;

        if (error?.retryable !== true) {
          break;
        }
      }

      if (attempt < this.maximumOutcomeAttempts) {
        await this.waitForOutcomeRetry(token, this.outcomeRetryDelay * attempt);
      }
    }

    if (this.isCurrent(token)) {
      this.runtime.markCanonicalActionOutcomeUnconfirmed(operationId, lastError);
    }

    return null;
  }

  resumeSubmission(task, forceNative, submissionState = null) {
    if (forceNative) {
      this.form.dataset.joomlaSubmissionTransport = 'native';
    }

    const liveState = submissionState
      ? submissionState.map(({ control }) => ({
        control,
        state: readControlState(control),
      }))
      : [];

    submissionState?.forEach(({ control, state }) => writeControlState(control, state));
    this.form.elements.task.value = task;
    this.bypass = true;

    try {
      // Continue through Joomla's original post-validation primitive. This
      // retains its task/submitter and downstream native/Ajax semantics.
      this.submitForm(task, this.form);
    } finally {
      this.bypass = false;
      liveState.forEach(({ control, state }) => writeControlState(control, state));
    }
  }

  setOperationMetadata(operationId, intent) {
    this.setHiddenValue(OPERATION_FIELD, operationId);
    this.setHiddenValue(INTENT_FIELD, intent);
  }

  setHiddenValue(name, value) {
    let input = this.form.elements?.namedItem?.(name);

    if (!input) {
      input = this.form.ownerDocument.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.dataset.autosaveCanonicalMetadata = 'created';
      this.form.appendChild(input);
    } else {
      input.dataset.autosaveCanonicalMetadata = 'reused';
    }

    input.value = value;
    this.metadataInputs.add(input);
  }

  clearOperationMetadata() {
    this.metadataInputs.forEach((input) => {
      if (input.dataset.autosaveCanonicalMetadata === 'created') {
        input.remove();
      } else {
        input.value = '';
        delete input.dataset.autosaveCanonicalMetadata;
      }
    });
    this.metadataInputs.clear();
  }

  waitForOutcomeRetry(token, delay) {
    return new Promise((resolve) => {
      const timer = this.clock.setTimeout(() => {
        this.outcomeRetryWaiters.delete(timer);
        resolve(this.isCurrent(token));
      }, delay);

      this.outcomeRetryWaiters.set(timer, resolve);
    });
  }

  setBusy(busy) {
    if (busy) {
      this.form.setAttribute('aria-busy', 'true');
    } else {
      this.form.removeAttribute('aria-busy');
    }
  }

  isCurrent(token) {
    return !this.destroyed
      && this.form?.isConnected !== false
      && this.activeAction?.token === token;
  }

  destroy() {
    if (this.destroyed) {
      return;
    }

    this.destroyed = true;
    this.started = false;
    this.generation += 1;
    this.form.removeEventListener('submit', this.handleSubmit, true);
    this.completionCleanups.forEach((cleanup) => cleanup());
    this.completionCleanups.clear();
    this.outcomeRetryWaiters.forEach((resolve, timer) => {
      this.clock.clearTimeout(timer);
      resolve(false);
    });
    this.outcomeRetryWaiters.clear();
    this.clearOperationMetadata();
    this.setBusy(false);
    this.activeAction = null;
    this.runtime = null;
    this.submitForm = null;
    this.form = null;
  }
}

export {
  INTENT_FIELD,
  OPERATION_FIELD,
  captureSubmissionState,
  normalizeTaskPolicy,
};
