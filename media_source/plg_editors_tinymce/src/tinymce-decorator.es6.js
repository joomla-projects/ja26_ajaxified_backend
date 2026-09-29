/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import JoomlaEditorDecorator from 'editor-decorator';

/**
 * TinyMCE decorator for JoomlaEditor.
 */
export default class TinyMCEDecorator extends JoomlaEditorDecorator {
  /**
   * @returns {string}
   */
  getValue() {
    return this.instance.getContent();
  }

  /**
   * @param {string} value
   *
   * @returns {TinyMCEDecorator}
   */
  setValue(value) {
    this.performValueChange(() => this.instance.setContent(value));

    return this;
  }

  /**
   * @returns {string}
   */
  getSelection() {
    return this.instance.selection.getContent({ format: 'text' });
  }

  /**
   * @param {string} value
   *
   * @returns {TinyMCEDecorator}
   */
  replaceSelection(value) {
    this.performValueChange(() => this.instance.execCommand('mceInsertContent', false, value));

    return this;
  }

  /**
   * @param {boolean} enable
   *
   * @returns {TinyMCEDecorator}
   */
  disable(enable) {
    this.instance.setMode(!enable ? 'readonly' : 'design');

    return this;
  }

  /**
   * Attach TinyMCE's instance-level content observers.
   *
   * @param {Function} callback Dirty callback.
   *
   * @returns {Function}
   *
   * @protected
   */
  observeChanges(callback) {
    const events = 'input change Undo Redo';
    this.instance.on(events, callback);
    let observing = true;

    return () => {
      if (!observing) {
        return;
      }

      observing = false;
      this.instance.off(events, callback);
    };
  }

  /**
   * Toggles the editor visibility mode. Used by Toggle button.
   *
   * @param {boolean} show Optional. True to show, false to hide.
   *
   * @returns {boolean} True when the editor becomes visible, false when it becomes hidden.
   */
  toggle(show) {
    let visible = false;

    if (show || this.instance.isHidden()) {
      this.instance.show();
      visible = true;
    } else {
      this.instance.hide();
    }

    return visible;
  }
}
