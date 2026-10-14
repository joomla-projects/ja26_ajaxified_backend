/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
const inputs = (element, type) => Array.from(element.querySelectorAll('input[type="' + type + '"]'));
const calendar = (element) => globalThis.JoomlaCalendar?.getCalObject(element)?._joomlaCalendar;

/** Check whether the native control can participate in draft recovery. */
export const availableControl = (element, descriptor) => {
  if (!element) return false;
  if (descriptor.control === 'checkboxes') return inputs(element, 'checkbox').some((input) => !input.disabled);
  if (descriptor.control === 'radio') return inputs(element, 'radio').some((input) => !input.disabled);
  if (descriptor.control === 'calendar') return typeof calendar(element)?.setDate === 'function'
    && typeof calendar(element)?.callHandler === 'function' && typeof Date.parseFieldDate === 'function';
  if (descriptor.control === 'checkbox') return element.type === 'checkbox';
  if (descriptor.control === 'multiple') return element.multiple === true;
  return typeof element.value === 'string';
};

/** Capture the existing native value without changing the control. */
export const readControl = (element, descriptor) => {
  if (descriptor.control === 'checkbox') return element.checked ? '1' : '0';
  if (descriptor.control === 'checkboxes') return inputs(element, 'checkbox').filter((input) => input.checked && !input.disabled).map((input) => input.value);
  if (descriptor.control === 'radio') return inputs(element, 'radio').find((input) => input.checked)?.value || '';
  if (descriptor.control === 'multiple') return Array.from(element.selectedOptions, (option) => option.value);
  return element.value;
};

// Enhanced selects must update their native widget as well as the underlying select.
const restoreSelect = (element, descriptor, value) => {
  const multiple = descriptor.control === 'multiple';
  const values = multiple ? value : [value];
  const options = Array.from(element.options);
  const fancy = element.closest('joomla-field-fancy-select');
  const choices = fancy?.choicesInstance;

  if (!Array.isArray(values) || values.some((selected) => typeof selected !== 'string')) {
    throw new Error('Unsupported native selection');
  }
  const unavailable = values.filter((selected) => !options.some((option) => option.value === selected));
  if (unavailable.length && (!fancy?.allowCustom || typeof choices?.setChoices !== 'function')) {
    throw new Error('Unavailable native selection');
  }
  if (values.some((selected) => options.some((option) => option.value === selected
    && (option.disabled || option.parentElement?.disabled)))) {
    throw new Error('Unavailable native selection');
  }
  if (fancy && multiple && typeof choices?.removeActiveItems !== 'function') {
    throw new Error('Native multiple-select is unavailable');
  }

  if (unavailable.length) {
    // Use the same native choice API as typing a custom Module position.
    choices.setChoices(unavailable.map((selected) => {
      let label = selected;
      if (choices.config.allowHTML) {
        const text = element.ownerDocument.createElement('span');
        text.textContent = selected;
        label = text.innerHTML;
      }
      return { value: selected, label, selected: true };
    }), 'value', 'label', false);
  }

  if (fancy) {
    // Joomla's value setter adds choices; recovery must replace the selected set.
    if (multiple) choices.removeActiveItems();
    fancy.value = multiple ? values : value;
  } else if (multiple) {
    options.forEach((option) => { option.selected = values.includes(option.value); });
  } else {
    element.value = value;
  }
};

/** Restore a recoverable value through the control's native API. */
export const restoreControl = (element, descriptor, value) => {
  if (descriptor.control === 'checkbox') {
    if (!['0', '1'].includes(value)) {
      throw new Error('Unsupported checkbox value');
    }
    element.checked = value === '1';
  } else if (descriptor.control === 'checkboxes') {
    const options = inputs(element, 'checkbox');
    if (!Array.isArray(value) || value.some((selected) => !options.some((input) => input.value === selected && !input.disabled))) {
      throw new Error('Unavailable native checkbox choice');
    }
    options.filter((input) => !input.disabled).forEach((input) => { input.checked = value.includes(input.value); });
  } else if (descriptor.control === 'radio') {
    const options = inputs(element, 'radio');
    if (!options.some((input) => input.value === value && !input.disabled)) {
      throw new Error('Unavailable radio choice');
    }
    options.forEach((input) => { input.checked = input.value === value; });
  } else if (descriptor.control === 'select' || descriptor.control === 'multiple') {
    restoreSelect(element, descriptor, value);
  } else if (descriptor.control === 'calendar') {
    const native = calendar(element);
    if (!availableControl(element, descriptor)) throw new Error('Native calendar is unavailable');
    if (value === '') {
      element.value = '';
      element.setAttribute('data-alt-value', '');
    } else {
      const date = Date.parseFieldDate(value, native.params.dateFormat, native.params.dateType, native.strings, true);
      if (!(date instanceof Date) || Number.isNaN(date.getTime())) throw new Error('Unsupported native date');
      native.setDate(date);
      native.callHandler();
    }
  } else element.value = value;
};
