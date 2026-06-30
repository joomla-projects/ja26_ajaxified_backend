/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

const LOADER_ID = 'joomla-submission-loader';
const DELAY = 250;

const activeTokens = new Set();
const formCounts = new Map();

let timer = null;
let loader = null;

const getLoader = () => {
    if (loader?.isConnected) {
        return loader;
    }

    loader = document.getElementById(LOADER_ID);

    return loader;
};

const getLoadingText = () => window.Joomla?.Text?._?.('JLOADING') || 'Loading';

/**
 * Mark a form as busy while one or more submissions are active.
 *
 * @param {HTMLFormElement} form
 *
 * @returns {void}
 */
const setFormBusy = (form) => {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const count = formCounts.get(form) || 0;

    if (count === 0) {
        form.setAttribute('aria-busy', 'true');
    }

    formCounts.set(form, count + 1);
};

/**
 * Remove one busy reference from a form.
 *
 * @param {HTMLFormElement} form
 *
 * @returns {void}
 */
const unsetFormBusy = (form) => {
    if (!(form instanceof HTMLFormElement) || !formCounts.has(form)) {
        return;
    }

    const count = formCounts.get(form) - 1;

    if (count > 0) {
        formCounts.set(form, count);

        return;
    }

    formCounts.delete(form);
    form.removeAttribute('aria-busy');
};

/**
 * Show the Joomla core loader.
 *
 * @returns {void}
 */
const showLoader = () => {
    if (getLoader()) {
        return;
    }

    const element = document.createElement('joomla-core-loader');

    element.id = LOADER_ID;
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.setAttribute('aria-label', getLoadingText());

    document.body.appendChild(element);

    loader = element;
};

/**
 * Hide the Joomla core loader.
 *
 * @returns {void}
 */
const hideLoader = () => {
    if (timer) {
        window.clearTimeout(timer);
        timer = null;
    }

    getLoader()?.remove();
    loader = null;

    document.documentElement.classList.remove('joomla-submission-active');
};

/**
 * Start tracking progress for a form submission.
 *
 * @param {HTMLFormElement} form
 *
 * @returns {Function}
 */
const start = (form) => {
    const token = Symbol('submission-progress');
    let stopped = false;

    activeTokens.add(token);
    setFormBusy(form);

    document.documentElement.classList.add('joomla-submission-active');

    if (!timer && !getLoader()) {
        timer = window.setTimeout(() => {
            timer = null;

            if (activeTokens.size > 0) {
                showLoader();
            }
        }, DELAY);
    }

    return () => {
        if (stopped) {
            return;
        }

        stopped = true;

        activeTokens.delete(token);
        unsetFormBusy(form);

        if (activeTokens.size > 0) {
            return;
        }

        hideLoader();
    };
};

/**
 * Reset all progress state.
 *
 * @returns {void}
 */
const reset = () => {
    activeTokens.clear();

    for (const form of formCounts.keys()) {
        form.removeAttribute('aria-busy');
    }

    formCounts.clear();
    hideLoader();
};

window.addEventListener('pagehide', reset);

export default {
    start,
    reset,
};
