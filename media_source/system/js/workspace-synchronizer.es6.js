/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

export default class WorkspaceSynchronizer {
    /**
     * Synchronize workspace identity from a response snapshot.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {void}
     */
    static synchronize(snapshot) {
        const { document: detachedDocument, response } = snapshot;

        this.synchronizeHistory(response);

        const detachedForm = detachedDocument.querySelector('form[name="adminForm"]');
        const liveForm = document.querySelector('form[name="adminForm"]');

        this.synchronizeFormAction(detachedForm, liveForm);
        this.synchronizeFormControls(detachedForm, liveForm);
    }

    /**
     * Synchronize the browser history state and URL.
     *
     * @param {Response} response
     *
     * @returns {void}
     */
    static synchronizeHistory(response) {
        history.replaceState(
            history.state,
            '',
            response.url,
        );
    }

    /**
     * Synchronize form action metadata.
     *
     * @param {HTMLFormElement} detachedForm
     * @param {HTMLFormElement} liveForm
     *
     * @returns {void}
     */
    static synchronizeFormAction(detachedForm, liveForm) {
        if (liveForm && detachedForm) {
            if (liveForm.action !== detachedForm.action) {
                liveForm.action = detachedForm.action;
            }
        }
    }

    /**
     * Synchronize canonical form controls.
     *
     * @param {HTMLFormElement} detachedForm
     * @param {HTMLFormElement} liveForm
     *
     * @returns {void}
     */
    static synchronizeFormControls(detachedForm, liveForm) {
        if (!detachedForm || !liveForm) {
            return;
        }

        const synchronizedNames = new Set();

        for (const detached of detachedForm.elements) {
            const name = detached.name;
            if (!name || synchronizedNames.has(name)) continue;

            const liveElements = liveForm.querySelectorAll(`[name="${CSS.escape(name)}"]`);
            const detachedElements = detachedForm.querySelectorAll(`[name="${CSS.escape(name)}"]`);

            if (detachedElements.length !== liveElements.length) {
                continue;
            }

            for (let i = 0; i < detachedElements.length; i++) {
                this.synchronizeControl(detachedElements[i], liveElements[i]);
            }

            synchronizedNames.add(name);
        }
    }

    /**
     * Synchronize semantic state of a matching control.
     *
     * @param {Element} detached
     * @param {Element} live
     *
     * @returns {void}
     */
    static synchronizeControl(detached, live) {
        switch (detached.tagName) {
            case 'INPUT':
                switch (detached.type) {
                    case 'checkbox':
                    case 'radio':
                        if (live.checked !== detached.checked) {
                            live.checked = detached.checked;
                        }
                        break;

                    case 'submit':
                    case 'button':
                    case 'reset':
                    case 'file':
                        // Buttons and file inputs are not synchronized
                        break;

                    default:
                        // Text-like inputs, hidden, number, date, email, etc.
                        if (live.value !== detached.value) {
                            live.value = detached.value;
                        }
                        break;
                }
                break;

            case 'TEXTAREA':
                if (live.value !== detached.value) {
                    live.value = detached.value;
                }
                break;

            case 'SELECT':
                if (detached.multiple) {
                    const selectedValues = new Set(
                        Array.from(detached.selectedOptions).map((option) => option.value)
                    );
                    for (const option of live.options) {
                        const isSelected = selectedValues.has(option.value);
                        if (option.selected !== isSelected) {
                            option.selected = isSelected;
                        }
                    }
                } else {
                    if (live.selectedIndex !== detached.selectedIndex) {
                        live.selectedIndex = detached.selectedIndex;
                    }
                }
                break;

            default:
                break;
        }
    }
}
