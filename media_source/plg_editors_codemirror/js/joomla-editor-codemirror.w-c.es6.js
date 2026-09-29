/**
 * @copyright  (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import { JoomlaEditor } from 'editor-api';
import { createFromTextarea, keymap } from 'codemirror';
import {
  CodemirrorDecorator,
  createCodeMirrorChangeObserver,
} from '../src/codemirror-decorator.es6.js';

class CodemirrorEditor extends HTMLElement {
  constructor() {
    super();

    this.toggleFullScreen = () => {
      if (!this.classList.contains('fullscreen')) {
        this.classList.add('fullscreen');
        document.documentElement.scrollTop = 0;
        document.documentElement.style.overflow = 'hidden';
      } else {
        this.closeFullScreen();
      }
    };

    this.closeFullScreen = () => {
      this.classList.remove('fullscreen');
      document.documentElement.style.overflow = '';
    };

    this.interactionCallback = () => {
      JoomlaEditor.setActive(this.element.id);
    };
  }

  get options() { return JSON.parse(this.getAttribute('options')); }

  get fsCombo() { return this.getAttribute('fs-combo'); }

  async connectedCallback() {
    const initialization = Symbol('codemirror-initialization');
    this.initialization = initialization;
    const { options } = this;
    const changeObserver = createCodeMirrorChangeObserver();
    options.customExtensions = options.customExtensions || [];
    options.customExtensions.push(() => changeObserver.extension);

    // Configure full screen feature
    if (this.fsCombo) {
      options.customExtensions.push(() => keymap.of([
        { key: this.fsCombo, run: this.toggleFullScreen },
        { key: 'Escape', run: this.closeFullScreen },
      ]));

      // Relocate BS modals, to resolve z-index issue in full screen
      this.bsModals = this.querySelectorAll('.joomla-modal.modal');
      this.bsModals.forEach((modal) => document.body.appendChild(modal));
    }

    // Create and register the Editor
    const element = this.querySelector('textarea');
    this.element = element;
    const instance = await createFromTextarea(element, options);

    if (this.initialization !== initialization || !this.isConnected) {
      element.style.display = '';
      instance.destroy();

      return;
    }

    this.instance = instance;
    this.jEditor = new CodemirrorDecorator(
      this.instance,
      'codemirror',
      element.id,
      changeObserver.subscribe,
    );
    JoomlaEditor.register(this.jEditor);

    // Find out when editor is interacted
    this.addEventListener('click', this.interactionCallback);
  }

  disconnectedCallback() {
    this.initialization = null;
    this.removeEventListener('click', this.interactionCallback);

    // Remove subscriptions while the provider instance is still available.
    if (this.jEditor) {
      JoomlaEditor.unregister(this.jEditor);
    }

    this.jEditor = null;

    if (this.instance) {
      this.element.style.display = '';
      this.instance.destroy();
    }

    this.instance = null;
    this.element = null;

    // Restore modals
    if (this.bsModals && this.bsModals.length) {
      this.bsModals.forEach((modal) => this.appendChild(modal));
    }
  }
}

customElements.define('joomla-editor-codemirror', CodemirrorEditor);
