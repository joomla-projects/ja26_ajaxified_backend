/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';

import { CodemirrorDecorator, createCodeMirrorChangeObserver } from '../../../../media_source/plg_editors_codemirror/src/codemirror-decorator.es6.js';
import EditorNoneDecorator from '../../../../media_source/plg_editors_none/src/editor-none-decorator.es6.js';
import TinyMCEDecorator from '../../../../media_source/plg_editors_tinymce/src/tinymce-decorator.es6.js';

globalThis.Joomla = { editors: { instances: {} } };

const { JoomlaEditor } = await import('../../../../media_source/system/js/editors/editor-api.es6.js');

class FakeTinyMCE {
  constructor(value = 'initial') {
    this.value = value;
    this.listeners = new Map();
    this.offCalls = 0;
    this.selection = { getContent: () => '' };
  }

  on(names, callback) {
    names.split(' ').forEach((name) => {
      if (!this.listeners.has(name)) {
        this.listeners.set(name, new Set());
      }

      this.listeners.get(name).add(callback);
    });
  }

  off(names, callback) {
    this.offCalls += 1;
    names.split(' ').forEach((name) => this.listeners.get(name)?.delete(callback));
  }

  emit(name) {
    [...(this.listeners.get(name) || [])].forEach((callback) => callback({ content: this.value }));
  }

  getContent() {
    return this.value;
  }

  setContent(value) {
    this.value = value;
    this.emit('input');
  }

  execCommand(command, ui, value) {
    this.value += value;
    this.emit('input');
  }

  setMode() {}
}

class FakeTextarea extends EventTarget {
  constructor(value = 'initial') {
    super();
    this.value = value;
  }
}

const createNoneProvider = (value = 'initial') => {
  const textarea = new FakeTextarea(value);

  return {
    editor: textarea,
    getValue: () => textarea.value,
    setValue: (nextValue) => {
      textarea.value = nextValue;
    },
    getSelection: () => '',
    replaceSelection: (selection) => {
      textarea.value += selection;
    },
  };
};

const createCodeMirror = (value = 'initial') => {
  let content = value;
  const changeObserver = createCodeMirrorChangeObserver({ of: (callback) => callback });
  const editor = {
    state: null,
    dispatch(specification) {
      const previous = content;

      if (specification.changes) {
        content = specification.changes.insert;
      }

      this.state.doc = {
        length: content.length,
        toString: () => content,
      };
      changeObserver.extension({ docChanged: content !== previous });
    },
  };
  editor.state = {
    config: { compartments: new Map() },
    doc: {
      length: content.length,
      toString: () => content,
    },
    selection: { main: { from: 0, to: 0 } },
    sliceDoc: () => '',
    replaceSelection: (selection) => ({ changes: { insert: `${content}${selection}` } }),
  };

  return { changeObserver, editor };
};

afterEach(() => {
  Object.keys(JoomlaEditor.instances).forEach((id) => JoomlaEditor.unregister(id));
  JoomlaEditor.active = null;
});

const verifyObservation = ({ editor, emitNative, cleanupCount }) => {
  const argumentCounts = [];
  const unsubscribe = editor.subscribeChange((...args) => argumentCounts.push(args.length));

  emitNative();
  assert.deepEqual(argumentCounts, [0]);
  assert.equal(editor.getValue(), 'initial');

  unsubscribe();
  unsubscribe();
  emitNative();
  assert.deepEqual(argumentCounts, [0]);
  assert.equal(cleanupCount(), 1);
};

const verifyProgrammaticChanges = (editor) => {
  let calls = 0;
  const unsubscribe = editor.subscribeChange(() => {
    calls += 1;
  });

  assert.strictEqual(editor.setValue('updated'), editor);
  assert.equal(editor.getValue(), 'updated');
  assert.equal(calls, 1);

  assert.strictEqual(editor.setValue('updated'), editor);
  assert.equal(calls, 1);

  assert.strictEqual(editor.replaceSelection('!'), editor);
  assert.equal(calls, 2);
  unsubscribe();
};

test('TinyMCE exposes native changes, public setters, and complete cleanup', () => {
  const instance = new FakeTinyMCE();
  const editor = new TinyMCEDecorator(instance, 'tinymce', 'tiny');

  verifyObservation({
    editor,
    emitNative: () => instance.emit('Undo'),
    cleanupCount: () => instance.offCalls,
  });
  verifyProgrammaticChanges(editor);

  let calls = 0;
  editor.subscribeChange(() => {
    calls += 1;
  });
  editor.releaseChangeSubscriptions();
  instance.emit('input');
  assert.equal(calls, 0);
});

test('None editor exposes textarea changes, public setters, and complete cleanup', () => {
  const instance = createNoneProvider();
  const editor = new EditorNoneDecorator(instance, 'none', 'plain');
  let removedListeners = 0;
  const originalRemove = instance.editor.removeEventListener.bind(instance.editor);
  instance.editor.removeEventListener = (...args) => {
    removedListeners += 1;
    originalRemove(...args);
  };

  verifyObservation({
    editor,
    emitNative: () => instance.editor.dispatchEvent(new Event('input')),
    cleanupCount: () => removedListeners / 2,
  });
  verifyProgrammaticChanges(editor);

  let calls = 0;
  editor.subscribeChange(() => {
    calls += 1;
  });
  editor.releaseChangeSubscriptions();
  instance.editor.dispatchEvent(new Event('change'));
  assert.equal(calls, 0);
});

test('CodeMirror exposes document updates, public setters, and complete cleanup', () => {
  const { changeObserver, editor: instance } = createCodeMirror();
  const editor = new CodemirrorDecorator(instance, 'codemirror', 'code', changeObserver.subscribe);
  let cleanupCalls = 0;
  const originalSubscribe = editor.changeObserver;
  editor.changeObserver = (callback) => {
    const unsubscribe = originalSubscribe(callback);

    return () => {
      cleanupCalls += 1;
      unsubscribe();
    };
  };

  verifyObservation({
    editor,
    emitNative: () => changeObserver.extension({ docChanged: true }),
    cleanupCount: () => cleanupCalls,
  });
  verifyProgrammaticChanges(editor);

  let calls = 0;
  editor.subscribeChange(() => {
    calls += 1;
  });
  editor.releaseChangeSubscriptions();
  changeObserver.extension({ docChanged: true });
  assert.equal(calls, 0);
});

test('CodeMirror ignores selection-only updates', () => {
  const { changeObserver, editor: instance } = createCodeMirror();
  const editor = new CodemirrorDecorator(instance, 'codemirror', 'code', changeObserver.subscribe);
  let calls = 0;
  const unsubscribe = editor.subscribeChange(() => {
    calls += 1;
  });

  changeObserver.extension({ docChanged: false });
  assert.equal(calls, 0);
  unsubscribe();
});

test('stale core provider decorators cannot unregister newer replacements', async (t) => {
  const verifyProvider = ({ first, second, emitSecond }) => {
    let secondCalls = 0;
    JoomlaEditor.register(first);
    JoomlaEditor.register(second);
    const unsubscribe = second.subscribeChange(() => {
      secondCalls += 1;
    });

    JoomlaEditor.unregister(first);
    emitSecond();

    assert.strictEqual(JoomlaEditor.get(second.getId()), second);
    assert.equal(secondCalls, 1);
    unsubscribe();
    JoomlaEditor.unregister(second);
  };

  await t.test('TinyMCE', () => {
    const firstInstance = new FakeTinyMCE();
    const secondInstance = new FakeTinyMCE();
    verifyProvider({
      first: new TinyMCEDecorator(firstInstance, 'tinymce', 'shared-tiny'),
      second: new TinyMCEDecorator(secondInstance, 'tinymce', 'shared-tiny'),
      emitSecond: () => secondInstance.emit('input'),
    });
  });

  await t.test('None', () => {
    const firstInstance = createNoneProvider();
    const secondInstance = createNoneProvider();
    verifyProvider({
      first: new EditorNoneDecorator(firstInstance, 'none', 'shared-none'),
      second: new EditorNoneDecorator(secondInstance, 'none', 'shared-none'),
      emitSecond: () => secondInstance.editor.dispatchEvent(new Event('input')),
    });
  });

  await t.test('CodeMirror', () => {
    const firstHarness = createCodeMirror();
    const secondHarness = createCodeMirror();
    verifyProvider({
      first: new CodemirrorDecorator(
        firstHarness.editor,
        'codemirror',
        'shared-code',
        firstHarness.changeObserver.subscribe,
      ),
      second: new CodemirrorDecorator(
        secondHarness.editor,
        'codemirror',
        'shared-code',
        secondHarness.changeObserver.subscribe,
      ),
      emitSecond: () => secondHarness.changeObserver.extension({ docChanged: true }),
    });
  });
});
