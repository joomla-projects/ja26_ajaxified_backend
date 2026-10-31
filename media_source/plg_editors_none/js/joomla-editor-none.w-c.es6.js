/**
 * @copyright  (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import { JoomlaEditor } from 'editor-api';
import EditorNoneDecorator from '../src/editor-none-decorator.es6.js';

class JoomlaEditorNone extends HTMLElement {
  constructor() {
    super();

    // Properties
    this.editor = '';
    this.jEditor = null;

    // Bindings
    this.unregisterEditor = this.unregisterEditor.bind(this);
    this.registerEditor = this.registerEditor.bind(this);
    this.childrenChange = this.childrenChange.bind(this);
    this.getSelection = this.getSelection.bind(this);

    // Watch for children changes.
    new MutationObserver(() => this.childrenChange())
      .observe(this, { childList: true });

    // Find out when editor is interacted
    this.interactionCallback = () => {
      if (this.editor) {
        JoomlaEditor.setActive(this.editor.id);
      }
    };
  }

  /**
   * Lifecycle
   */
  connectedCallback() {
    // Note the mutation observer won't fire for initial contents,
    // so childrenChange is also called here.
    this.childrenChange();
    this.addEventListener('click', this.interactionCallback);
  }

  /**
   * Lifecycle
   */
  disconnectedCallback() {
    this.unregisterEditor();
    this.editor = '';
    this.removeEventListener('click', this.interactionCallback);
  }

  /**
   * Get editor value
   */
  getValue() {
    return this.editor.value;
  }

  /**
   * Set editor value
   * @param {string} text
   */
  setValue(text) {
    this.editor.value = text;
  }

  /**
   * Get the selected text
   */
  getSelection() {
    if (this.editor.selectionStart || this.editor.selectionStart === 0) {
      return this.editor.value.substring(this.editor.selectionStart, this.editor.selectionEnd);
    }
    return this.editor.value;
  }

  /**
   * Replace selected text
   * @param {string} text
   */
  replaceSelection(text) {
    const ed = this.editor;
    if (ed.selectionStart || ed.selectionStart === 0) {
      ed.value = ed.value.substring(0, ed.selectionStart)
        + text
        + ed.value.substring(ed.selectionEnd, ed.value.length);
    } else {
      ed.value += text;
    }
  }

  /**
   * Register the editor
   */
  registerEditor() {
    if (!this.editor || JoomlaEditor.get(this.editor.id)) {
      return;
    }

    this.jEditor = new EditorNoneDecorator(this, 'none', this.editor.id);
    JoomlaEditor.register(this.jEditor);
  }

  /**
   * Remove the editor from the Joomla API
   */
  unregisterEditor() {
    if (this.jEditor) {
      JoomlaEditor.unregister(this.jEditor);
      this.jEditor = null;
    }
  }

  /**
   * Called when element's child list changes
   */
  childrenChange() {
    const nextEditor = this.firstElementChild;
    const supported = this.isConnected
      && nextEditor
      && nextEditor.tagName
      && nextEditor.tagName.toLowerCase() === 'textarea'
      && nextEditor.getAttribute('id');

    if (!supported) {
      this.unregisterEditor();
      this.editor = '';

      return;
    }

    if (this.editor === nextEditor && JoomlaEditor.get(nextEditor.id)) {
      return;
    }

    this.unregisterEditor();
    this.editor = nextEditor;
    this.registerEditor();
  }
}

customElements.define('joomla-editor-none', JoomlaEditorNone);
