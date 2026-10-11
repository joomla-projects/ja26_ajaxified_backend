/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import JoomlaEditorDecorator from 'editor-decorator';
import { EditorState, EditorView } from 'codemirror';

/**
 * Create the instance-local CodeMirror change bridge before EditorView construction.
 *
 * @param {Object} updateListener CodeMirror update-listener facet.
 *
 * @returns {{extension: Object, subscribe: Function}}
 */
const createCodeMirrorChangeObserver = (updateListener = EditorView.updateListener) => {
  const callbacks = new Set();
  const extension = updateListener.of((update) => {
    if (update.docChanged) {
      [...callbacks].forEach((callback) => callback());
    }
  });

  return {
    extension,
    subscribe(callback) {
      callbacks.add(callback);
      let subscribed = true;

      return () => {
        if (!subscribed) {
          return;
        }

        subscribed = false;
        callbacks.delete(callback);
      };
    },
  };
};

/**
 * CodeMirror decorator for JoomlaEditor.
 */
class CodemirrorDecorator extends JoomlaEditorDecorator {
  /**
   * @param {Object} instance CodeMirror EditorView.
   * @param {string} type Editor type.
   * @param {string} id Editor ID.
   * @param {Function} changeObserver Instance-local change subscription function.
   */
  constructor(instance, type, id, changeObserver) {
    super(instance, type, id);

    if (typeof changeObserver !== 'function') {
      throw new TypeError('CodeMirror change observer must be a function');
    }

    this.changeObserver = changeObserver;
  }

  /**
   * @returns {string}
   */
  getValue() {
    return this.instance.state.doc.toString();
  }

  /**
   * @param {string} value
   *
   * @returns {CodemirrorDecorator}
   */
  setValue(value) {
    this.performValueChange(() => {
      const editor = this.instance;
      editor.dispatch({
        changes: { from: 0, to: editor.state.doc.length, insert: value },
      });
    });

    return this;
  }

  /**
   * @returns {string}
   */
  getSelection() {
    const { state } = this.instance;

    return state.sliceDoc(
      state.selection.main.from,
      state.selection.main.to,
    );
  }

  /**
   * @param {string} value
   *
   * @returns {CodemirrorDecorator}
   */
  replaceSelection(value) {
    this.performValueChange(() => {
      const transaction = this.instance.state.replaceSelection(value);
      this.instance.dispatch(transaction);
    });

    return this;
  }

  /**
   * @param {boolean} enable
   *
   * @returns {CodemirrorDecorator}
   */
  disable(enable) {
    const editor = this.instance;
    editor.state.config.compartments.forEach((facet, compartment) => {
      if (compartment.$j_name === 'readOnly') {
        editor.dispatch({
          effects: compartment.reconfigure(EditorState.readOnly.of(!enable)),
        });
      }
    });

    return this;
  }

  /**
   * Attach the instance-local CodeMirror content observer.
   *
   * @param {Function} callback Dirty callback.
   *
   * @returns {Function}
   *
   * @protected
   */
  observeChanges(callback) {
    return this.changeObserver(callback);
  }
}

export { CodemirrorDecorator, createCodeMirrorChangeObserver };
