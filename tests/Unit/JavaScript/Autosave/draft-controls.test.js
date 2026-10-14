/**
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { createContext, Script } from 'node:vm';
import { availableControl, readControl, restoreControl } from '../../../../media_source/com_autosave/js/draft-controls.es6.js';

const group = (options) => ({ querySelectorAll: () => options });

let FancySelect;
const nativeContext = createContext({
  HTMLElement: class {},
  document: { createElement: () => ({}), documentElement: { style: {} } },
  window: { customElements: { define: (name, component) => { FancySelect = component; } } },
});
new Script(await readFile('node_modules/choices.js/public/assets/scripts/choices.js', 'utf8')).runInContext(nativeContext);
new Script(await readFile('media_source/system/js/fields/joomla-field-fancy-select.w-c.es6.js', 'utf8')).runInContext(nativeContext);

// Exercise Joomla's actual setter and Choices' public selection methods.
// DOM rendering and item mutations are the fixture boundary.
const nativeMultiple = (options) => {
  options.forEach((option, index) => {
    option.id = index + 1;
  });
  const choices = Object.assign(Object.create(nativeContext.Choices.prototype), {
    initialisedOK: true,
    config: { valueComparer: (left, right) => left === right },
    _searcher: { reset: () => {} },
    _store: {
      choices: options,
      get items() { return options.filter((option) => option.selected); },
      withTxn: (callback) => callback(),
    },
    _addItem: (option) => { option.selected = true; },
    _removeItem: (option) => { option.selected = false; },
    unhighlightAll: () => {},
  });
  const fancy = Object.assign(Object.create(FancySelect.prototype), { choicesInstance: choices, hasAttribute: () => false });
  const element = {
    multiple: true, options, closest: () => fancy,
    get selectedOptions() { return options.filter((option) => option.selected); },
  };
  return { element, fancy };
};

test('native radio recovery cannot select a disabled or removed choice', () => {
  const options = [{ value: '0', checked: true, disabled: false }, { value: '1', checked: false, disabled: true }];
  const element = group(options);
  assert.equal(readControl(element, { control: 'radio' }), '0');
  assert.throws(() => restoreControl(element, { control: 'radio' }, '1'));
  assert.equal(options[0].checked, true);
  options[1].disabled = false;
  restoreControl(element, { control: 'radio' }, '1');
  assert.equal(options[0].checked, false);
  assert.equal(options[1].checked, true);
});

test('native checkbox-group recovery preserves excluded disabled choices and rejects foreign IDs', () => {
  const options = [{ value: '1', checked: true, disabled: true }, { value: '3', checked: false, disabled: false }];
  const element = group(options);
  assert.equal(availableControl(element, { control: 'checkboxes' }), true);
  restoreControl(element, { control: 'checkboxes' }, ['3']);
  assert.equal(options[0].checked, true);
  assert.deepEqual(readControl(element, { control: 'checkboxes' }), ['3']);
  assert.throws(() => restoreControl(element, { control: 'checkboxes' }, ['99']));
  assert.throws(() => restoreControl(element, { control: 'checkboxes' }, ['1']));
});

test('native fancy multiple-select replaces an existing selection exactly', () => {
  const { element, fancy } = nativeMultiple([{ value: 'a', selected: true }, { value: 'b', selected: false }]);
  restoreControl(element, { control: 'multiple' }, ['b']);
  assert.deepEqual(readControl(element, { control: 'multiple' }), ['b']);
  assert.deepEqual(Array.from(fancy.value), ['b']);
});

test('native fancy multiple-select clears all selections for an empty snapshot', () => {
  const { element } = nativeMultiple([{ value: 'a', selected: true }, { value: 'b', selected: true }]);
  restoreControl(element, { control: 'multiple' }, []);
  assert.deepEqual(readControl(element, { control: 'multiple' }), []);
});

test('native fancy multiple-select retains current values when recovery is invalid', () => {
  const { element } = nativeMultiple([{ value: 'a', selected: true }, { value: 'b', selected: false, disabled: true }]);
  for (const value of [['b'], ['foreign'], ['a', 'foreign'], 'b']) {
    assert.throws(() => restoreControl(element, { control: 'multiple' }, value));
    assert.deepEqual(readControl(element, { control: 'multiple' }), ['a']);
  }
});

test('native fancy multiple-select rejects a missing replacement API without changing values', () => {
  const { element, fancy } = nativeMultiple([{ value: 'a', selected: true }, { value: 'b', selected: false }]);
  fancy.choicesInstance.removeActiveItems = undefined;
  assert.throws(() => restoreControl(element, { control: 'multiple' }, ['b']));
  assert.deepEqual(readControl(element, { control: 'multiple' }), ['a']);
});

test('plain multiple-select retains native replacement and empty-selection behavior', () => {
  const options = [{ value: 'a', selected: true }, { value: 'b', selected: false }];
  const element = {
    multiple: true, options, closest: () => null,
    get selectedOptions() { return options.filter((option) => option.selected); },
  };
  restoreControl(element, { control: 'multiple' }, ['b']);
  assert.deepEqual(readControl(element, { control: 'multiple' }), ['b']);
  restoreControl(element, { control: 'multiple' }, []);
  assert.deepEqual(readControl(element, { control: 'multiple' }), []);
});

test('native custom multiple-select replaces old values through the existing widget APIs', () => {
  const { element, fancy } = nativeMultiple([{ value: 'a', selected: true }]);
  fancy.hasAttribute = (name) => name === 'allow-custom';
  fancy.choicesInstance.setChoices = (choices) => {
    element.options.push(...choices.map((choice, index) => ({ ...choice, id: element.options.length + index + 1 })));
  };
  restoreControl(element, { control: 'multiple' }, ['custom']);
  assert.deepEqual(readControl(element, { control: 'multiple' }), ['custom']);
  assert.deepEqual(Array.from(fancy.value), ['custom']);
});

test('calendar recovery uses the native parser and calendar methods instead of a second date implementation', (t) => {
  const previous = Date.parseFieldDate;
  const previousCalendar = globalThis.JoomlaCalendar;
  t.after(() => {
    if (previousCalendar) globalThis.JoomlaCalendar = previousCalendar;
    else delete globalThis.JoomlaCalendar;
  });
  t.after(() => {
    if (previous) Date.parseFieldDate = previous;
    else delete Date.parseFieldDate;
  });
  const date = new Date('2026-10-13T00:00:00Z');
  let restored;
  let notifications = 0;
  Date.parseFieldDate = (value, format, type) => {
    assert.equal(value, 'Native date');
    assert.equal(format, 'native-format');
    assert.equal(type, 'gregorian');
    return date;
  };
  const native = { params: { dateFormat: 'native-format', dateType: 'gregorian' }, strings: {},
    setDate: (value) => { restored = value; },
    callHandler: () => { notifications += 1; } };
  const attributes = {};
  const element = { value: 'Current date', closest: () => ({ _joomlaCalendar: native }),
    setAttribute: (name, value) => { attributes[name] = value; } };
  globalThis.JoomlaCalendar = { getCalObject: (field) => field.closest() };
  assert.equal(availableControl(element, { control: 'calendar' }), true);
  restoreControl(element, { control: 'calendar' }, 'Native date');
  assert.equal(restored, date);
  assert.equal(notifications, 1);
  restoreControl(element, { control: 'calendar' }, '');
  assert.equal(element.value, '');
  assert.equal(attributes['data-alt-value'], '');
  element.closest = () => null;
  assert.equal(availableControl(element, { control: 'calendar' }), false);
  assert.throws(() => restoreControl(element, { control: 'calendar' }, 'Native date'));
});

test('native custom positions use allow-custom choices; ordinary selects reject foreign options', () => {
  let choices;
  const fancy = { allowCustom: true, choicesInstance: { config: { allowHTML: false },
    setChoices: (value) => { choices = value; } } };
  const element = { options: [{ value: 'sidebar' }], closest: () => fancy };
  restoreControl(element, { control: 'select' }, 'custom-position');
  assert.deepEqual(choices, [{ value: 'custom-position', label: 'custom-position', selected: true }]);
  assert.equal(fancy.value, 'custom-position');
  fancy.allowCustom = false;
  assert.throws(() => restoreControl(element, { control: 'select' }, 'foreign'));
  fancy.allowCustom = true;
  element.options[0].disabled = true;
  assert.throws(() => restoreControl(element, { control: 'select' }, 'sidebar'));
});
