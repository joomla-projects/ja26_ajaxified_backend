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
    static synchronize(
        snapshot,
        strategy = { synchronizeControls: true },
        submissionState = null
    ) {
        const { document: detachedDocument, response } = snapshot;
        const detachedForm = detachedDocument.querySelector('form[name="adminForm"]');
        const liveForm = document.querySelector('form[name="adminForm"]');

        if (submissionState?.form && submissionState.form !== liveForm) {
            return;
        }

        this.synchronizeHistory(response);

        this.synchronizeFormAction(detachedForm, liveForm);

        if (strategy.synchronizeControls) {
            this.synchronizeFormControls(detachedForm, liveForm, submissionState);
        }
    }

    /**
     * Capture normalized control values before an Ajax request begins.
     *
     * @param {HTMLFormElement} form
     *
     * @returns {{form: HTMLFormElement, controls: Map}|null}
     */
    static captureFormControls(form) {
        if (!(form instanceof HTMLFormElement)) {
            return null;
        }

        const captured = new Map();

        for (const control of form.elements) {
            if (!control.name) {
                continue;
            }

            if (!captured.has(control.name)) {
                captured.set(control.name, []);
            }

            captured.get(control.name).push(this.readControl(control));
        }

        return Object.freeze({
            form,
            controls: captured,
        });
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
    static synchronizeFormControls(detachedForm, liveForm, submissionState = null) {
        if (!detachedForm || !liveForm) {
            return;
        }

        if (submissionState?.form && submissionState.form !== liveForm) {
            return;
        }

        const submittedControls = submissionState?.controls || null;

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
                const submitted = submittedControls?.get(name)?.[i];

                if (submitted !== undefined
                    && !this.sameControlValue(this.readControl(liveElements[i]), submitted)) {
                    continue;
                }

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

    /**
     * Read one control without exposing DOM objects in the snapshot.
     *
     * @param {Element} control
     *
     * @returns {string|boolean|string[]|null}
     */
    static readControl(control) {
        if (!control) {
            return null;
        }

        if (control.tagName === 'INPUT'
            && (control.type === 'checkbox' || control.type === 'radio')) {
            return [control.checked, control.value];
        }

        if (control.tagName === 'SELECT' && control.multiple) {
            return Array.from(control.selectedOptions).map((option) => option.value);
        }

        if ('value' in control) {
            return control.value;
        }

        return null;
    }

    /**
     * Compare normalized submitted and current control values.
     */
    static sameControlValue(first, second) {
        if (Array.isArray(first) || Array.isArray(second)) {
            return Array.isArray(first)
                && Array.isArray(second)
                && first.length === second.length
                && first.every((value, index) => value === second[index]);
        }

        return first === second;
    }
}
