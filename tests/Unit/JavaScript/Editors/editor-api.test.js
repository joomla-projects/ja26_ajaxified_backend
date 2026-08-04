/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';

globalThis.Joomla = { editors: { instances: {} } };

const {
  JoomlaEditor,
  JoomlaEditorChangeObservationError,
  JoomlaEditorDecorator,
} = await import('../../../../media_source/system/js/editors/editor-api.es6.js');

class LegacyCompatibleEditor extends JoomlaEditorDecorator {
  constructor(id = 'editor', value = 'initial') {
    super({ privateProvider: true }, 'test', id);
    this.value = value;
    this.getValueCalls = 0;
  }

  getValue() {
    this.getValueCalls += 1;

    return this.value;
  }

  setValue(value) {
    this.value = value;

    return this;
  }
}

class ObservableEditor extends LegacyCompatibleEditor {
  constructor(id = 'editor', value = 'initial') {
    super(id, value);
    this.providerListeners = new Set();
    this.providerSubscriptions = 0;
    this.providerCleanups = 0;
  }

  observeChanges(callback) {
    this.providerSubscriptions += 1;
    this.providerListeners.add(callback);
    let observing = true;

    return () => {
      if (!observing) {
        return;
      }

      observing = false;
      this.providerCleanups += 1;
      this.providerListeners.delete(callback);
    };
  }

  emitChange() {
    [...this.providerListeners].forEach((callback) => callback());
  }
}

class NormalizedObservableEditor extends ObservableEditor {
  setValue(value) {
    this.performValueChange(() => {
      this.value = value;
    });

    return this;
  }
}

afterEach(() => {
  Object.keys(JoomlaEditor.instances).forEach((id) => JoomlaEditor.unregister(id));
  JoomlaEditor.active = null;
});

test('existing getValue and setValue behavior remains unchanged', () => {
  const editor = new LegacyCompatibleEditor();

  assert.equal(editor.getValue(), 'initial');
  assert.strictEqual(editor.setValue('updated'), editor);
  assert.equal(editor.getValue(), 'updated');
});

test('subscribeChange uses the provider hook without reading or exposing content', () => {
  const editor = new ObservableEditor();
  let callbackArguments = null;
  const callsBeforeSubscribe = editor.getValueCalls;
  const unsubscribe = editor.subscribeChange((...args) => {
    callbackArguments = args;
  });

  assert.equal(editor.supportsChangeObservation(), true);
  assert.equal(editor.getValueCalls, callsBeforeSubscribe);
  assert.equal(editor.providerSubscriptions, 1);

  editor.emitChange();
  assert.deepEqual(callbackArguments, []);
  assert.equal(callbackArguments.includes(editor.getRawInstance()), false);
  assert.equal(editor.getValueCalls, callsBeforeSubscribe);

  unsubscribe();
  unsubscribe();
  assert.equal(editor.providerCleanups, 1);
});

test('programmatic observation compares only when subscribed and preserves return behavior', () => {
  const editor = new NormalizedObservableEditor();

  assert.strictEqual(editor.setValue('without-observer'), editor);
  assert.equal(editor.getValueCalls, 0);

  let calls = 0;
  const unsubscribe = editor.subscribeChange(() => {
    calls += 1;
  });
  assert.strictEqual(editor.setValue('changed'), editor);
  assert.equal(calls, 1);
  assert.equal(editor.getValueCalls, 2);

  assert.strictEqual(editor.setValue('changed'), editor);
  assert.equal(calls, 1);
  assert.equal(editor.getValueCalls, 4);

  const readsBeforeNativeChange = editor.getValueCalls;
  editor.emitChange();
  assert.equal(calls, 2);
  assert.equal(editor.getValueCalls, readsBeforeNativeChange);
  unsubscribe();
});

test('multiple subscriptions are independent and provider cleanup follows the last one', () => {
  const editor = new ObservableEditor();
  let firstCalls = 0;
  let secondCalls = 0;
  const callback = () => {
    firstCalls += 1;
  };
  const unsubscribeFirst = editor.subscribeChange(callback);
  const unsubscribeDuplicate = editor.subscribeChange(callback);
  const unsubscribeSecond = editor.subscribeChange(() => {
    secondCalls += 1;
  });

  assert.equal(editor.providerSubscriptions, 1);
  editor.emitChange();
  assert.equal(firstCalls, 2);
  assert.equal(secondCalls, 1);

  unsubscribeFirst();
  editor.emitChange();
  assert.equal(firstCalls, 3);
  assert.equal(secondCalls, 2);
  assert.equal(editor.providerCleanups, 0);

  unsubscribeDuplicate();
  unsubscribeSecond();
  assert.equal(editor.providerCleanups, 1);
  editor.emitChange();
  assert.equal(firstCalls, 3);
  assert.equal(secondCalls, 2);
});

test('removed subscribers are skipped during notification and callback errors are isolated', () => {
  const editor = new ObservableEditor();
  const callbackError = new Error('broken consumer');
  const reportedErrors = [];
  const originalReportError = globalThis.reportError;
  globalThis.reportError = (error) => reportedErrors.push(error);
  let secondCalls = 0;
  let thirdCalls = 0;
  let unsubscribeSecond;
  const unsubscribeFirst = editor.subscribeChange(() => {
    unsubscribeSecond();
    throw callbackError;
  });
  unsubscribeSecond = editor.subscribeChange(() => {
    secondCalls += 1;
  });
  const unsubscribeThird = editor.subscribeChange(() => {
    thirdCalls += 1;
  });

  try {
    editor.emitChange();
    assert.equal(secondCalls, 0);
    assert.equal(thirdCalls, 1);
    assert.deepEqual(reportedErrors, [callbackError]);
  } finally {
    unsubscribeFirst();
    unsubscribeSecond();
    unsubscribeThird();
    globalThis.reportError = originalReportError;
  }
});

test('invalid callbacks fail predictably and unsupported providers remain registerable', () => {
  const editor = new LegacyCompatibleEditor('third-party');

  assert.strictEqual(JoomlaEditor.register(editor), JoomlaEditor);
  assert.strictEqual(JoomlaEditor.get('third-party'), editor);
  assert.equal(editor.supportsChangeObservation(), false);
  assert.throws(() => editor.subscribeChange(null), TypeError);
  assert.throws(
    () => editor.subscribeChange(() => {}),
    (error) => error instanceof JoomlaEditorChangeObservationError
      && error.code === 'unsupported_change_observation',
  );
});

test('unregister releases provider listeners and prevents later notifications', () => {
  const editor = new ObservableEditor('teardown');
  let calls = 0;
  JoomlaEditor.register(editor);
  editor.subscribeChange(() => {
    calls += 1;
  });

  JoomlaEditor.unregister(editor);
  JoomlaEditor.unregister(editor);
  editor.emitChange();

  assert.equal(calls, 0);
  assert.equal(editor.providerCleanups, 1);
  assert.equal(JoomlaEditor.get('teardown'), false);
});

test('lifecycle notifications contain immutable metadata and follow registry visibility', () => {
  const editor = new ObservableEditor('lifecycle');
  const notifications = [];
  const unsubscribe = JoomlaEditor.subscribeLifecycle((detail) => {
    notifications.push({
      detail,
      available: JoomlaEditor.get(detail.id) !== false,
    });
  });

  JoomlaEditor.register(editor);
  JoomlaEditor.unregister(editor);
  unsubscribe();
  unsubscribe();

  assert.deepEqual(notifications.map(({ detail }) => detail), [
    { type: 'registered', id: 'lifecycle', editorType: 'test' },
    { type: 'unregistered', id: 'lifecycle', editorType: 'test' },
  ]);
  assert.deepEqual(notifications.map(({ available }) => available), [true, false]);
  assert.ok(notifications.every(({ detail }) => Object.isFrozen(detail)));
  assert.ok(notifications.every(({ detail }) => !('content' in detail) && !('editor' in detail)));
});

test('lifecycle subscriptions and duplicate registry operations are independent', () => {
  const editor = new ObservableEditor('duplicate');
  const firstEvents = [];
  const secondEvents = [];
  const unsubscribeFirst = JoomlaEditor.subscribeLifecycle((detail) => firstEvents.push(detail.type));
  const unsubscribeSecond = JoomlaEditor.subscribeLifecycle((detail) => secondEvents.push(detail.type));

  JoomlaEditor.register(editor);
  JoomlaEditor.register(editor);
  unsubscribeFirst();
  unsubscribeFirst();
  JoomlaEditor.unregister('duplicate');
  JoomlaEditor.unregister('duplicate');
  unsubscribeSecond();

  assert.deepEqual(firstEvents, ['registered']);
  assert.deepEqual(secondEvents, ['registered', 'unregistered']);
});

test('replacement registration reports the old removal before the new availability', () => {
  const first = new ObservableEditor('replacement');
  const second = new ObservableEditor('replacement');
  const notifications = [];
  const unsubscribe = JoomlaEditor.subscribeLifecycle((detail) => {
    notifications.push({
      type: detail.type,
      current: JoomlaEditor.get(detail.id),
    });
  });

  JoomlaEditor.register(first);
  JoomlaEditor.register(second);
  unsubscribe();

  assert.deepEqual(notifications.map(({ type }) => type), [
    'registered',
    'unregistered',
    'registered',
  ]);
  assert.strictEqual(notifications[0].current, first);
  assert.equal(notifications[1].current, false);
  assert.strictEqual(notifications[2].current, second);
});

test('a stale editor instance cannot unregister or clean up its replacement', () => {
  const first = new ObservableEditor('stale');
  const second = new ObservableEditor('stale');
  const lifecycle = [];
  const unsubscribeLifecycle = JoomlaEditor.subscribeLifecycle((detail) => lifecycle.push(detail.type));
  let secondCalls = 0;

  JoomlaEditor.register(first);
  JoomlaEditor.register(second);
  const unsubscribeSecond = second.subscribeChange(() => {
    secondCalls += 1;
  });
  JoomlaEditor.unregister(first);
  second.emitChange();

  assert.strictEqual(JoomlaEditor.get('stale'), second);
  assert.equal(secondCalls, 1);
  assert.equal(second.providerCleanups, 0);
  assert.deepEqual(lifecycle, ['registered', 'unregistered', 'registered']);

  unsubscribeLifecycle();
  unsubscribeSecond();
  JoomlaEditor.unregister(second);
});

test('lifecycle removal during notification and subscriber errors cannot block later callbacks', () => {
  const editor = new ObservableEditor('lifecycle-errors');
  const callbackError = new Error('broken lifecycle consumer');
  const reportedErrors = [];
  const originalReportError = globalThis.reportError;
  globalThis.reportError = (error) => reportedErrors.push(error);
  let secondCalls = 0;
  let thirdCalls = 0;
  let unsubscribeSecond;
  const unsubscribeFirst = JoomlaEditor.subscribeLifecycle(() => {
    unsubscribeSecond();
    throw callbackError;
  });
  unsubscribeSecond = JoomlaEditor.subscribeLifecycle(() => {
    secondCalls += 1;
  });
  const unsubscribeThird = JoomlaEditor.subscribeLifecycle(() => {
    thirdCalls += 1;
  });

  try {
    JoomlaEditor.register(editor);
    assert.strictEqual(JoomlaEditor.get('lifecycle-errors'), editor);
    assert.equal(secondCalls, 0);
    assert.equal(thirdCalls, 1);
    assert.deepEqual(reportedErrors, [callbackError]);
  } finally {
    unsubscribeFirst();
    unsubscribeSecond();
    unsubscribeThird();
    globalThis.reportError = originalReportError;
    JoomlaEditor.unregister(editor);
  }
});

test('invalid lifecycle callbacks fail predictably', () => {
  assert.throws(() => JoomlaEditor.subscribeLifecycle(undefined), TypeError);
});
