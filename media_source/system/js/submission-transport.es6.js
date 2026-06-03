/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/*
 * Track active transport requests per form.
 *
 * WeakMap ensures entries are released when
 * forms are removed from the document.
 */
const activeForms = new WeakMap();

/**
 * Create an error indicating that a submission
 * cannot start because the form already has an
 * active transport request.
 *
 * @returns {Error}
 */
const createSubmissionBlockedError = () => {
    const error = new Error('Submission already in progress');

    error.name = 'SubmissionBlockedError';

    return error;
};

/**
 * Submission transport infrastructure.
 */
export default class SubmissionTransport {
    /**
     * Execute a transport request.
     *
     * @param {SubmissionContext} context
     *
     * @returns {Promise<Response>}
     */
    static async send(context) {
        if (!context || !(context.form instanceof HTMLFormElement)) {
            throw new TypeError('Expected SubmissionContext');
        }

        const form = context.form;

        if (activeForms.has(form)) {
            throw createSubmissionBlockedError();
        }

        activeForms.set(form, true);

        try {
            const method = (form.getAttribute('method') || 'POST').toUpperCase();
            const action = form.getAttribute('action') || window.location.href;

            const formData = new FormData(form);

            const response = await fetch(action, {
                method,
                body: formData,
            });

            return response;
        } finally {
            activeForms.delete(form);
        }
    }
}
