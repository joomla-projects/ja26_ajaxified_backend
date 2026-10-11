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
globalThis.Node = {
  ELEMENT_NODE: 1,
  PROCESSING_INSTRUCTION_NODE: 7,
  COMMENT_NODE: 8,
};
globalThis.NodeFilter = { SHOW_PROCESSING_INSTRUCTION: 128 };

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

const { default: ResponseSnapshot } = await import(
  '../../../../media_source/system/js/response-snapshot.es6.js'
);
const { serializeBoundary } = await import(
  '../../../../media_source/system/js/boundary-synchronizer.es6.js'
);

class FakeProcessingInstruction {
  constructor(target, data) {
    this.nodeType = 7;
    this.target = target;
    this.data = data;
    this.textContent = data;
    this.nextSibling = null;
  }

  cloneNode() {
    return new FakeProcessingInstruction(this.target, this.data);
  }
}

class FakeComment {
  constructor(nodeValue) {
    this.nodeType = 8;
    this.nodeValue = nodeValue;
    this.textContent = nodeValue;
    this.nextSibling = null;
  }

  cloneNode() {
    return new FakeComment(this.nodeValue);
  }
}

class FakeElement {
  constructor(tagName, outerHTML) {
    this.nodeType = 1;
    this.tagName = tagName;
    this.outerHTML = outerHTML;
    this.nextSibling = null;
  }

  cloneNode() {
    return new FakeElement(this.tagName, this.outerHTML);
  }
}

test('ResponseSnapshot boundary depth tracking handles nested boundaries without premature truncation', () => {
  const pStartWorkspace = new FakeProcessingInstruction('start', 'name="workspace"');
  const pStartToolbar = new FakeProcessingInstruction('start', 'name="toolbar"');
  const divToolbar = new FakeElement('DIV', '<div id="toolbar"></div>');
  const pEndToolbar = new FakeProcessingInstruction('end', '');
  const divContent = new FakeElement('DIV', '<div id="content"></div>');
  const pEndWorkspace = new FakeProcessingInstruction('end', '');

  pStartWorkspace.nextSibling = pStartToolbar;
  pStartToolbar.nextSibling = divToolbar;
  divToolbar.nextSibling = pEndToolbar;
  pEndToolbar.nextSibling = divContent;
  divContent.nextSibling = pEndWorkspace;

  const nodes = [pStartWorkspace, pStartToolbar, divToolbar, pEndToolbar, divContent, pEndWorkspace];

  const fakeDoc = {
    body: {},
    createTreeWalker: () => {
      let index = -1;
      const pis = nodes.filter((n) => n.nodeType === 7);
      return {
        nextNode: () => {
          index += 1;
          return pis[index] || null;
        },
      };
    },
  };

  const snapshot = Object.create(ResponseSnapshot.prototype);
  snapshot.document = fakeDoc;

  const boundary = snapshot.boundary('workspace');
  assert.ok(boundary);
  assert.equal(boundary.endNode, pEndWorkspace);
  assert.equal(boundary.payload.length, 4);
  assert.equal(boundary.payload.includes(divContent), true);
});

test('ResponseSnapshot boundary extraction matches exact boundary names', () => {
  const pStartExtra = new FakeProcessingInstruction('start', 'name="toolbar_extra"');
  const pEndExtra = new FakeProcessingInstruction('end', '');
  const pStartToolbar = new FakeProcessingInstruction('start', 'name="toolbar"');
  const divToolbar = new FakeElement('DIV', '<div id="toolbar"></div>');
  const pEndToolbar = new FakeProcessingInstruction('end', '');

  pStartExtra.nextSibling = pEndExtra;
  pEndExtra.nextSibling = pStartToolbar;
  pStartToolbar.nextSibling = divToolbar;
  divToolbar.nextSibling = pEndToolbar;

  const nodes = [pStartExtra, pEndExtra, pStartToolbar, divToolbar, pEndToolbar];

  const fakeDoc = {
    body: {},
    createTreeWalker: () => {
      let index = -1;
      const pis = nodes.filter((n) => n.nodeType === 7);
      return {
        nextNode: () => {
          index += 1;
          return pis[index] || null;
        },
      };
    },
  };

  const snapshot = Object.create(ResponseSnapshot.prototype);
  snapshot.document = fakeDoc;

  const boundary = snapshot.boundary('toolbar');
  assert.ok(boundary);
  assert.equal(boundary.startNode, pStartToolbar);
  assert.equal(boundary.payload.length, 1);
  assert.equal(boundary.payload[0], divToolbar);
});

test('boundary serialization preserves nested Joomla boundary markers', () => {
  const html = serializeBoundary('workspace', [
    new FakeProcessingInstruction('start', 'name="toolbar"'),
    new FakeElement('DIV', '<div id="toolbar"></div>'),
    new FakeProcessingInstruction('end', ''),
    new FakeComment('preserve comment'),
  ]);

  assert.equal(
    html,
    '<template for="workspace"><?start name="workspace"?>'
      + '<?start name="toolbar"?><div id="toolbar"></div><?end?>'
      + '<!--preserve comment--><?end?></template>',
  );
});
