/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

export default class BootstrapRuntime {
    /**
     * Activate Bootstrap modals within the given root boundary.
     *
     * @param {Element} root
     *
     * @returns {void}
     */
    static activate(root) {
        if (!root) return;

        // Get bootstrap modal settings from Joomla options
        const modals = Joomla.getOptions('bootstrap.modal');
        if (typeof modals !== 'object' || modals === null) {
            return;
        }

        Object.keys(modals).forEach((selector) => {
            const opt = modals[selector];
            const options = {
                backdrop: opt.backdrop ?? true,
                keyboard: opt.keyboard ?? true,
                focus: opt.focus ?? true,
            };

            // Find all matching elements within the root boundary
            const elements = [];
            if (root.matches && root.matches(selector)) {
                elements.push(root);
            }
            if (root.querySelectorAll) {
                elements.push(...root.querySelectorAll(selector));
            }

            elements.forEach((modalEl) => {
                // Move the modal to document.body to prevent backdrop/clipping issues
                if (modalEl.parentNode && modalEl.parentNode !== document.body) {
                    document.body.appendChild(modalEl);
                }

                // Ensure the element isn't already initialized
                if (window.bootstrap && window.bootstrap.Modal && !window.bootstrap.Modal.getInstance(modalEl)) {
                    if (typeof Joomla.initialiseModal === 'function') {
                        Joomla.initialiseModal(modalEl, options);
                    }
                }
            });
        });
    }
}
