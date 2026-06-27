/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

const LOADER_ID = 'joomla-submission-loader';
const DELAY = 250;

const getLoadingLabel = () => window.Joomla?.Text?._?.('JLOADING') || 'Loading';

/**
 * Coordinates visual busy state for enhanced submissions.
 */
class SubmissionProgress {
    constructor() {
        this.timer = null;
        this.loader = null;
        this.active = 0;
        this.forms = new WeakMap();
    }

    /**
     * Start tracking progress for a form submission.
     *
     * @param {HTMLFormElement} form
     *
     * @returns {void}
     */
    start(form) {
        this.active += 1;

        if (form instanceof HTMLFormElement) {
            this.forms.set(form, (this.forms.get(form) || 0) + 1);
            form.setAttribute('aria-busy', 'true');
        }

        document.documentElement.classList.add('joomla-submission-active');

        if (!this.timer && !this.loader) {
            this.timer = window.setTimeout(() => {
                this.timer = null;
                this.show();
            }, DELAY);
        }
    }

    /**
     * Stop tracking progress for a form submission.
     *
     * @param {HTMLFormElement} form
     *
     * @returns {void}
     */
    stop(form) {
        this.active = Math.max(0, this.active - 1);

        if (form instanceof HTMLFormElement) {
            const count = Math.max(0, (this.forms.get(form) || 0) - 1);

            if (count > 0) {
                this.forms.set(form, count);
            } else {
                this.forms.delete(form);
                form.removeAttribute('aria-busy');
            }
        }

        if (this.active > 0) {
            return;
        }

        if (this.timer) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }

        document.documentElement.classList.remove('joomla-submission-active');

        if (this.loader) {
            this.loader.remove();
            this.loader = null;
        }
    }

    /**
     * Show the Joomla core loader.
     *
     * @returns {void}
     */
    show() {
        if (this.active === 0) {
            return;
        }

        const existingLoader = document.getElementById(LOADER_ID);

        if (existingLoader) {
            this.loader = existingLoader;

            return;
        }

        const loader = document.createElement('joomla-core-loader');

        loader.id = LOADER_ID;
        loader.setAttribute('role', 'status');
        loader.setAttribute('aria-live', 'polite');
        loader.setAttribute('aria-label', getLoadingLabel());

        document.body.appendChild(loader);

        this.loader = loader;
    }
}

export default new SubmissionProgress();
