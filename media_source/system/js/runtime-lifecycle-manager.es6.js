/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import BootstrapRuntime from './bootstrap-runtime.es6.js';

export default class RuntimeLifecycleManager {
    /**
     * Activate the newly updated DOM.
     *
     * @param {Element} root
     *
     * @returns {void}
     */
    static activate(root) {
        if (!root) return;

        BootstrapRuntime.activate(root);

        root.dispatchEvent(new CustomEvent(
            'joomla:updated',
            {
                bubbles: true,
                detail: {
                    target: root,
                },
            }
        ));
    }
}
