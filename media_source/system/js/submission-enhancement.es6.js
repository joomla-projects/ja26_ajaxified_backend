/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

import SubmissionContext from './submission-context.es6.js';
import SubmissionEligibility from './submission-eligibility.es6.js';
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

    console.log('Submission eligibility');
    console.log(decision);

    if (!decision.eligible) {
        return;
    }

    event.preventDefault();

    console.log('Native submission prevented');

    console.log('Context created');
    console.log(context);

    console.log('Calling transport');

    const response = await SubmissionTransport.send(context);

    console.log('Transport response received');
    console.log(response);

    await SubmissionSynchronization.synchronize({
        response,
        context,
    });
}

document.addEventListener('submit', handleSubmit);