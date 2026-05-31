/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
function handleSubmit(event) {
  const form = event.target;

  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  if (!isEligibleSubmission(form)) {
    return;
  }

  // Future enhancement entry point.
}

document.addEventListener('submit', handleSubmit);
