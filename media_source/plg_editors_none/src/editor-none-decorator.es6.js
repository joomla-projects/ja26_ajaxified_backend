/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import JoomlaEditorDecorator from 'editor-decorator';

/**
 * Plain textarea decorator for JoomlaEditor.
 */
export default class EditorNoneDecorator extends JoomlaEditorDecorator {
  /**
   * @returns {string}
   */
  getValue() {
    return this.instance.getValue();
  }

  /**
   * @param {string} value
   *
   * @returns {EditorNoneDecorator}
   */
  setValue(value) {
    this.performValueChange(() => this.instance.setValue(value));

    return this;
  }

  /**
   * @returns {string}
   */
  getSelection() {
    return this.instance.getSelection();
  }

  /**
   * @param {string} value
   *
   * @returns {EditorNoneDecorator}
   */
  replaceSelection(value) {
    this.performValueChange(() => this.instance.replaceSelection(value));

    return this;
  }

  /**
   * @param {boolean} enable
   *
   * @returns {EditorNoneDecorator}
   */
  disable(enable) {
    if (this.instance.editor) {
      this.instance.editor.disabled = !enable;
      this.instance.editor.readOnly = !enable;
    }

    return this;
  }

  /**
   * Attach native textarea content observers.
   *
   * @param {Function} callback Dirty callback.
   *
   * @returns {Function}
   *
   * @protected
   */
  observeChanges(callback) {
    const textarea = this.instance.editor;
    textarea.addEventListener('input', callback);
    textarea.addEventListener('change', callback);
    let observing = true;

    return () => {
      if (!observing) {
        return;
      }

      observing = false;
      textarea.removeEventListener('input', callback);
      textarea.removeEventListener('change', callback);
    };
  }
}
