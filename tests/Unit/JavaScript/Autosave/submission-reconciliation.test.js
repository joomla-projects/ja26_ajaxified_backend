/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import test from 'node:test';

class FakeControl {
  constructor(name, value, { tagName = 'INPUT', type = 'text', checked = false } = {}) {
    this.name = name;
    this.value = value;
    this.tagName = tagName;
    this.type = type;
    this.checked = checked;
  }
}

class FakeForm {
  constructor(controls) {
    this.elements = controls;
  }

  querySelectorAll(selector) {
    const match = selector.match(/^\[name="(.+)"\]$/);

    return match ? this.elements.filter((control) => control.name === match[1]) : [];
  }
}

globalThis.HTMLFormElement = FakeForm;
globalThis.CSS = { escape: (value) => value };

const { default: WorkspaceSynchronizer } = await import(
  '../../../../media_source/system/js/workspace-synchronizer.es6.js'
);
const { default: SubmissionSynchronization } = await import(
  '../../../../media_source/system/js/submission-synchronization.es6.js'
);

test('Ajax synchronization updates only controls unchanged since submission', () => {
  const title = new FakeControl('jform[title]', 'Submitted');
  const alias = new FakeControl('jform[alias]', 'submitted-alias');
  const live = new FakeForm([title, alias]);
  const submitted = WorkspaceSynchronizer.captureFormControls(live);
  title.value = 'Later local title';
  const detached = new FakeForm([
    new FakeControl('jform[title]', 'Canonical title'),
    new FakeControl('jform[alias]', 'canonical-alias'),
  ]);

  WorkspaceSynchronizer.synchronizeFormControls(detached, live, submitted);

  assert.equal(title.value, 'Later local title');
  assert.equal(alias.value, 'canonical-alias');
});

test('mutation-aware synchronization handles checkbox state and repeated names independently', () => {
  const first = new FakeControl('flags[]', 'one', { type: 'checkbox', checked: true });
  const second = new FakeControl('flags[]', 'two', { type: 'checkbox', checked: false });
  const live = new FakeForm([first, second]);
  const submitted = WorkspaceSynchronizer.captureFormControls(live);
  second.checked = true;
  const detached = new FakeForm([
    new FakeControl('flags[]', 'one', { type: 'checkbox', checked: false }),
    new FakeControl('flags[]', 'two', { type: 'checkbox', checked: false }),
  ]);

  WorkspaceSynchronizer.synchronizeFormControls(detached, live, submitted);

  assert.equal(first.checked, false);
  assert.equal(second.checked, true);
});

test('synchronization assigns values without generating duplicate dirty events', () => {
  const liveControl = new FakeControl('jform[catid]', '2');
  const live = new FakeForm([liveControl]);
  const submitted = WorkspaceSynchronizer.captureFormControls(live);
  const detached = new FakeForm([new FakeControl('jform[catid]', '3')]);
  let changes = 0;
  liveControl.dispatchEvent = () => { changes += 1; };

  WorkspaceSynchronizer.synchronizeFormControls(detached, live, submitted);

  assert.equal(liveControl.value, '3');
  assert.equal(changes, 0);
});

test('a stale Ajax response does not update controls on a replacement form', () => {
  const original = new FakeForm([new FakeControl('jform[title]', 'Submitted')]);
  const submitted = WorkspaceSynchronizer.captureFormControls(original);
  const replacementTitle = new FakeControl('jform[title]', 'Replacement local value');
  const replacement = new FakeForm([replacementTitle]);
  const detached = new FakeForm([new FakeControl('jform[title]', 'Stale response value')]);

  WorkspaceSynchronizer.synchronizeFormControls(detached, replacement, submitted);

  assert.equal(replacementTitle.value, 'Replacement local value');
});

test('a disconnected submitted form makes the entire Ajax response stale', () => {
  const form = new FakeForm([]);
  form.isConnected = false;
  const submissionState = WorkspaceSynchronizer.captureFormControls(form);

  assert.equal(SubmissionSynchronization.isCurrentSubmission({ form }, submissionState), false);
  assert.equal(
    SubmissionSynchronization.isCurrentSubmission({ form: new FakeForm([]) }, submissionState),
    false,
  );
  assert.equal(SubmissionSynchronization.isCurrentSubmission({ form }, null), true);
});
