/**
 * @copyright  (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

window.customElements.define('joomla-toolbar-button', class extends HTMLElement {
  // Attribute getters
  get task() { return this.getAttribute('task'); }

  get listSelection() { return this.hasAttribute('list-selection'); }

  get form() { return this.getAttribute('form'); }

  get formValidation() { return this.hasAttribute('form-validation'); }

  get confirmMessage() { return this.getAttribute('confirm-message'); }

  /**
   * Lifecycle
   */
  constructor() {
    super();

    if (!Joomla) {
      throw new Error('Joomla API is not properly initiated');
    }

    this.attachShadow({ mode: 'open' });

    this.slotElement = document.createElement('slot');

    this.shadowRoot.append(this.slotElement);

    this.confirmationReceived = false;
    this.onChange = this.onChange.bind(this);
    this.executeTask = this.executeTask.bind(this);
    this.syncButtonElement = this.syncButtonElement.bind(this);
  }

  /**
   * Lifecycle
   */
  connectedCallback() {
    // Check whether we have a form
    const formSelector = this.form || 'adminForm';
    this.formElement = document.getElementById(formSelector);

    this.disabled = false;
    // If list selection is required, set button to disabled by default
    if (this.listSelection) {
      this.setDisabled(true);
    }

    if (this.listSelection) {
      if (!this.formElement) {
        throw new Error(`The form "${formSelector}" is required to perform the task, but the form was not found on the page.`);
      }

      // Watch on list selection
      this.formElement.boxchecked.addEventListener('change', this.onChange);
    }

    this.slotElement.addEventListener('slotchange', this.syncButtonElement);
    this.syncButtonElement();
  }

  /**
   * Lifecycle
   */
  disconnectedCallback() {
    this.slotElement.removeEventListener('slotchange', this.syncButtonElement);

    if (this.formElement?.boxchecked) {
      this.formElement.boxchecked.removeEventListener('change', this.onChange);
    }

    if (this.buttonElement) {
      this.buttonElement.removeEventListener('click', this.executeTask);
      this.buttonElement = null;
    }
  }

  syncButtonElement() {
    // We need a button to support button behavior,
    // because we cannot currently extend HTMLButtonElement
    const assignedElements = this.slotElement.assignedElements({ flatten: true });
    const buttonElement = assignedElements.find((element) => element.matches('button, a'))
      || assignedElements
        .map((element) => element.querySelector?.('button, a'))
        .find(Boolean);

    if (!buttonElement || buttonElement === this.buttonElement) {
      return;
    }

    if (this.buttonElement) {
      this.buttonElement.removeEventListener('click', this.executeTask);
    }

    this.buttonElement = buttonElement;
    this.buttonElement.addEventListener('click', this.executeTask);
    this.setDisabled(this.disabled);
  }

  onChange({ target }) {
    // Check whether we have selected something
    this.setDisabled(target.value < 1);
  }

  setDisabled(disabled) {
    // Make sure we have a boolean value
    this.disabled = !!disabled;

    // Switch attribute for native element
    // An anchor does not support "disabled" attribute, so use class
    if (this.buttonElement) {
      if (this.disabled) {
        if (this.buttonElement.nodeName === 'BUTTON') {
          this.buttonElement.disabled = true;
        } else {
          this.buttonElement.classList.add('disabled');
        }
      } else if (this.buttonElement.nodeName === 'BUTTON') {
        this.buttonElement.disabled = false;
      } else {
        this.buttonElement.classList.remove('disabled');
      }
    }
  }

  executeTask() {
    if (this.disabled) {
      return false;
    }

    // Ask for User confirmation when needed
    if (this.confirmMessage && !this.confirmationReceived) {
      import('joomla.dialog')
        .then((m) => m.default.confirm(this.confirmMessage, Joomla.Text._('WARNING', 'Warning')))
        .then((confirmed) => {
          if (confirmed) {
            // Set confirmation flag, and emulate the click again
            this.confirmationReceived = true;
            this.buttonElement.click();
          }
        });
      return false;
    }

    // Reset any previous confirmation
    this.confirmationReceived = false;

    if (this.task) {
      Joomla.submitbutton(this.task, this.form, this.formValidation);
    }

    return true;
  }
});
