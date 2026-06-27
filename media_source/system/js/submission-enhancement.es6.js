/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

import SubmissionContext from './submission-context.es6.js';
import SubmissionEligibility from './submission-eligibility.es6.js';
import SubmissionProgress from './submission-progress.es6.js';
import SubmissionSynchronization from './submission-synchronization.es6.js';
import SubmissionTransport from './submission-transport.es6.js';

if (!window.Joomla) {
    throw new Error('Joomla API was not properly initialised');
}

/*
 * Determine whether the submitted form is eligible
 * for future enhancement processing.
 *
 * @param {HTMLFormElement} form
 *
 * @returns {boolean}
 */
function isEligibleSubmission(form) {
    return form instanceof HTMLFormElement
        && Boolean(form.elements.task);
}

/*
 * Observe administrator form submissions.
 *
 * @param {SubmitEvent} event
 */
async function handleSubmit(event) {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (!isEligibleSubmission(form)) {
        return;
    }

    const context = SubmissionContext.fromSubmitEvent(event);

    if (!context) {
        return;
    }

    const decision = SubmissionEligibility.evaluate(context);

    if (!decision.eligible) {
        return;
    }

    event.preventDefault();

    SubmissionProgress.start(context.form);

    try {
        const response = await SubmissionTransport.send(context);

        await SubmissionSynchronization.synchronize({
            response,
            context,
        });
    } catch (error) {
        if (error.name === 'SubmissionBlockedError') {
            return;
        }

        throw error;
    } finally {
        SubmissionProgress.stop(context.form);
    }
}

document.addEventListener('submit', handleSubmit);