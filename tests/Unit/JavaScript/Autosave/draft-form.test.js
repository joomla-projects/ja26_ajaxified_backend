/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { createContext, Script } from 'node:vm';
import { transformSync } from '@babel/core';
import DraftRuntime, { creationRecovery } from '../../../../media_source/com_autosave/js/drafts.es6.js';
import { fixture } from './draft-fixture.mjs';

const compile = (source) => new Script(transformSync(source, {
  babelrc: false, configFile: false, plugins: ['@babel/plugin-transform-modules-commonjs'],
}).code);
const binderScript = compile(await readFile('media_source/com_autosave/js/draft-form.es6.js', 'utf8'));
const controlsScript = compile(await readFile('media_source/com_autosave/js/draft-controls.es6.js', 'utf8'));

const setup = (t, { creating = false, late = false, plain = false, initialValue, history, prepare = () => {}, readEditor = (element) => element.value } = {}) => {
  const view = fixture();
  view.control.type = 'textarea';
  view.control.tagName = 'TEXTAREA';
  view.configuration.creating = creating;
  if (initialValue !== undefined) view.control.value = initialValue;
  if (plain) {
    view.control.type = 'text';
    view.control.tagName = 'INPUT';
  } else {
    view.configuration.fields.title.control = 'editor';
  }
  prepare(view);
  const callbacks = new Set();
  const entries = new Map();
  const editor = (element) => {
    const listeners = new Set();
    return {
      listeners, getElement: () => element, getValue: () => readEditor(element),
      setValue: (value) => { element.value = value; },
      subscribeChange: (callback) => {
        listeners.add(callback);
        return () => listeners.delete(callback);
      },
      notify: () => [...listeners].forEach((callback) => callback()),
    };
  };
  const editors = {
    get: (id) => entries.get(id),
    subscribeLifecycle: (callback) => {
      callbacks.add(callback);
      return () => callbacks.delete(callback);
    },
    register: (instance) => {
      entries.set('title', instance);
      callbacks.forEach((callback) => callback({ id: 'title', type: 'register' }));
    },
    unregister: () => {
      entries.delete('title');
      callbacks.forEach((callback) => callback({ id: 'title', type: 'unregister' }));
    },
  };
  const original = editor(view.control);
  if (!late) editors.register(original);
  const requests = [];
  let runtime;
  const Runtime = class extends DraftRuntime {
    constructor(options) {
      super({ ...options, debounce: 10000, csrfToken: () => 'native-token', fetcher: async (url, options) => {
        const body = JSON.parse(options.body);
        requests.push({ operation: new URL(url).searchParams.get('task'), body });
        const data = Object.hasOwn(body, 'payload')
          ? { client_revision: body.client_revision, updated_at: '2026-10-11T00:00:00Z', conflict: false }
          : { candidates: [], conflict: false };
        return { ok: true, json: async () => ({ success: true, data }) };
      } });
      runtime = this;
    }
  };
  const context = createContext({
    window: view.window, document: view.document, Event, AbortController, URL, DOMException,
    setTimeout, clearTimeout, exports: {},
  });
  controlsScript.runInContext(context);
  const controls = { ...context.exports, __esModule: true };
  context.exports = {};
  context.require = (name) => ({
    'com_autosave.drafts': { __esModule: true, default: Runtime, creationRecovery: (binding) => (history
      ? creationRecovery({ ...binding, context: 'com_content.article' }, history, 'navigate')
      : null) },
    'editor-api': { JoomlaEditor: editors },
    'joomla.dialog': { __esModule: true, default: {} },
    './draft-controls.es6.js': controls,
  })[name];
  binderScript.runInContext(context);
  const dispose = context.exports.default(view.form, view.configuration, editors);
  t.after(dispose);
  return { view, runtime, requests, editor, editors, original };
};

test('same-ID editor replacement resumes with the new value and one current subscription', async (t) => {
  const { view, runtime, requests, editor, editors, original } = setup(t);
  await new Promise((resolve) => setImmediate(resolve));
  const queued = [...original.listeners][0];
  const replacement = Object.assign(view.element(), { ...view.control, value: 'Replacement value', form: view.form });
  view.controls.splice(0, 1, replacement);
  editors.unregister();
  assert.equal(runtime.state, 'unavailable');
  assert.equal(original.listeners.size, 0);
  const current = editor(replacement);
  editors.register(current);
  await runtime.flush();
  assert.equal(runtime.state, 'preserved');
  assert.equal(requests.at(-1).body.payload.title, 'Replacement value');
  for (let index = 0; index < 5; index += 1) editors.register(current);
  assert.equal(current.listeners.size, 1);
  queued();
  assert.equal(runtime.state, 'preserved', 'A callback queued by the retired editor is inert.');
  replacement.value = 'Current live editor';
  current.notify();
  await runtime.flush();
  assert.equal(requests.at(-1).body.payload.title, 'Current live editor');
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 2);
});

for (const invalid of ['name', 'form', 'kind', 'duplicate', 'registry ownership', 'ownership error']) {
  test('replacement remains unavailable for invalid ' + invalid, async (t) => {
    const { view, runtime, requests, editor, editors, original } = setup(t);
    await new Promise((resolve) => setImmediate(resolve));
    const replacement = Object.assign(view.element(), { ...view.control, value: 'Untrusted replacement', form: view.form });
    if (invalid === 'name') replacement.name = 'jform[other]';
    if (invalid === 'form') replacement.form = {};
    if (invalid === 'kind') replacement.tagName = 'INPUT';
    view.controls.splice(0, 1, replacement);
    if (invalid === 'duplicate') view.controls.push(Object.assign({}, replacement));
    const current = editor(invalid === 'registry ownership' ? view.control : replacement);
    if (invalid === 'ownership error') {
      current.getElement = () => {
        throw new Error('Unsupported ownership');
      };
    }
    editors.unregister();
    editors.register(current);
    runtime.changed();
    await runtime.flush();
    assert.equal(runtime.state, 'unavailable');
    assert.equal(original.listeners.size, 0);
    assert.equal(current.listeners.size, 0);
    assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
  });
}

test('autosave observes edits automatically without optional toolbar controls', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
  const { view, runtime, requests, original } = setup(t);
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(view.nodes.has('[data-draft-toggle]'), false);
  assert.equal(view.nodes.has('[data-draft-coverage-details]'), false);
  view.control.value = 'Automatically preserved edit';
  original.notify();
  t.mock.timers.tick(10000);
  await runtime.inFlight;
  assert.equal(runtime.state, 'preserved');
  assert.equal(requests.at(-1).body.payload.title, 'Automatically preserved edit');
  assert.equal(original.listeners.size, 1);
});

const signal = (view, target, type) => {
  const event = new Event(type);
  Object.defineProperty(event, 'target', { value: target });
  view.form.dispatchEvent(event);
};

for (const creating of [true, false]) {
  test('unchanged editor notifications do not create dirty work for creating=' + creating, async (t) => {
    const { runtime, requests, original } = setup(t, { creating });
    await new Promise((resolve) => setImmediate(resolve));
    for (let index = 0; index < 3; index += 1) original.notify();
    await runtime.flush();
    assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
    assert.equal(runtime.revision, 0);
    assert.equal(runtime.pending, false);
    assert.equal(runtime.state, 'clean');
  });

  test('native editor serialization establishes the initial baseline for creating=' + creating, async (t) => {
    const { runtime, requests, original } = setup(t, { creating, initialValue: '', readEditor: () => '<p></p>' });
    await new Promise((resolve) => setImmediate(resolve));
    original.notify();
    await runtime.flush();
    assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
    assert.equal(runtime.revision, 0);
    assert.equal(runtime.state, 'clean');
  });
}

test('late first editor initialization does not manufacture a draft', async (t) => {
  const { runtime, requests, original, editors } = setup(t, { creating: true, late: true, readEditor: () => '<p></p>' });
  await new Promise((resolve) => setImmediate(resolve));
  editors.register(original);
  original.notify();
  await runtime.flush();
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
  assert.equal(runtime.revision, 0);
  assert.equal(runtime.state, 'clean');
});

test('plain controls ignore unchanged and excluded events but preserve real edits', async (t) => {
  const { view, runtime, requests } = setup(t, { plain: true });
  await new Promise((resolve) => setImmediate(resolve));
  signal(view, view.control, 'input');
  signal(view, view.control, 'change');
  signal(view, { value: 'Excluded content' }, 'input');
  await runtime.flush();
  assert.equal(runtime.revision, 0);
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
  view.control.value = 'Real protected edit';
  signal(view, view.control, 'input');
  signal(view, view.control, 'change');
  await runtime.flush();
  assert.equal(runtime.revision, 1);
  assert.equal(requests.at(-1).body.payload.title, 'Real protected edit');
});

test('typing into the original textarea before editor availability is retained', async (t) => {
  const { view, runtime, requests, original, editors } = setup(t, { creating: true, late: true });
  await new Promise((resolve) => setImmediate(resolve));
  view.control.value = 'Typed before editor initialization';
  signal(view, view.control, 'input');
  editors.register(original);
  await runtime.flush();
  assert.equal(requests.at(-1).body.payload.title, 'Typed before editor initialization');
});

for (const creating of [true, false]) {
  test('intentional clearing remains recoverable for creating=' + creating, async (t) => {
    const { view, runtime, requests, original } = setup(t, { creating, initialValue: '' });
    await new Promise((resolve) => setImmediate(resolve));
    view.control.value = 'Genuine work';
    original.notify();
    signal(view, view.control, 'change');
    await runtime.flush();
    view.control.value = '';
    original.notify();
    await runtime.flush();
    original.notify();
    await runtime.flush();
    const writes = requests.filter((request) => request.operation === 'draft.preserve');
    assert.deepEqual(writes.map((request) => request.body.payload.title), ['Genuine work', '']);
    assert.deepEqual(writes.map((request) => request.body.client_revision), [1, 2]);
  });
}

for (const control of ['multiple', 'checkboxes']) {
  test('unchanged native array values stay clean and changed selections are preserved: ' + control, async (t) => {
    const options = [{ value: 'a', selected: true, checked: true }, { value: 'b', selected: false, checked: false }];
    const { view, runtime, requests } = setup(t, { plain: true, prepare: (fixture) => {
      fixture.configuration.fields.title.control = control;
      fixture.control.multiple = true;
      fixture.control.querySelectorAll = () => options;
      Object.defineProperty(fixture.control, 'selectedOptions', { get: () => options.filter((option) => option.selected) });
    } });
    await new Promise((resolve) => setImmediate(resolve));
    signal(view, view.control, 'change');
    await runtime.flush();
    assert.equal(runtime.revision, 0);
    assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
    options[0].selected = false;
    options[0].checked = false;
    options[1].selected = true;
    options[1].checked = true;
    signal(view, view.control, 'change');
    await runtime.flush();
    assert.deepEqual(requests.at(-1).body.payload.title, ['b']);
    assert.equal(runtime.revision, 1);
  });
}

test('untouched creation keeps no reload recovery reference; real edits retain one', async (t) => {
  const history = {
    state: null,
    replaceState(state) {
      this.state = state;
    },
  };
  const { view, runtime, requests, original } = setup(t, { creating: true, initialValue: '', history });
  await new Promise((resolve) => setImmediate(resolve));
  original.notify();
  await runtime.flush();
  assert.equal(history.state, null);
  const binding = { creating: true, context: 'com_content.article', draftId: 'b'.repeat(32) };
  assert.deepEqual(creationRecovery(binding, history, 'reload').sources, {});
  view.control.value = 'Recoverable work';
  original.notify();
  await runtime.flush();
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 1);
  assert.deepEqual(creationRecovery(binding, history, 'reload').sources, {
    creation_id: 'a'.repeat(32), acknowledged_id: 'a'.repeat(32),
  });
});

test('continuous genuine editor changes meet the dirty deadline and idle forms do not write', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
  const { view, runtime, requests, original } = setup(t, { initialValue: '' });
  await new Promise((resolve) => setImmediate(resolve));
  for (let index = 0; index < 300; index += 1) {
    view.control.value = 'Edit ' + index;
    original.notify();
    t.mock.timers.tick(100);
  }
  await runtime.inFlight;
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 1);
  assert.equal(requests.at(-1).body.payload.title, 'Edit 299');
  t.mock.timers.tick(90000);
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 1);
});

test('pre-initialization undo to the original value does not create an empty draft', async (t) => {
  const { view, runtime, requests, original, editors } = setup(t, {
    creating: true, late: true, initialValue: '', readEditor: (element) => (element.value === '' ? '<p></p>' : element.value),
  });
  await new Promise((resolve) => setImmediate(resolve));
  view.control.value = 'Temporary typing';
  signal(view, view.control, 'input');
  view.control.value = '';
  signal(view, view.control, 'input');
  editors.register(original);
  await runtime.flush();
  assert.equal(requests.filter((request) => request.operation === 'draft.preserve').length, 0);
  assert.equal(runtime.state, 'clean');
});
