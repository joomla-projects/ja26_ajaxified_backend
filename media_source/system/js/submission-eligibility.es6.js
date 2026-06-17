/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

const REASONS = {
    CONTEXT_PRESERVING_EDIT: 'context-preserving-edit',
    LIST_ACTION: 'list-action',
    WORKSPACE_EXIT: 'workspace-exit',
    UNSUPPORTED_EMPTY_TASK: 'unsupported-empty-task',
};

/**
 * Determine whether a submission has no normalized action.
 *
 * @param {SubmissionContext} context
 *
 * @returns {boolean}
 */
const isEmptyTaskSubmission = (context) => !context.action;

/**
 * Retrieve the server-defined eligibility policy.
 *
 * @returns {Object<string, string>}
 */
const getEligibilityPolicy = () => Joomla.getOptions(
    'submission-eligibility',
    {}
);

/**
 * Evaluate submission eligibility.
 */
export default class SubmissionEligibility {
    /**
     * Evaluate a submission context.
     *
     * @param {SubmissionContext} context
     *
     * @returns {{eligible: boolean, reason: string}}
     */
    static evaluate(context) {
        if (isEmptyTaskSubmission(context)) {
            return {
                eligible: false,
                reason: REASONS.UNSUPPORTED_EMPTY_TASK,
            };
        }

        const policy = getEligibilityPolicy();

        const decision = policy[context.action];

        if (decision === 'ajax') {
            return {
                eligible: true,
                reason: REASONS.CONTEXT_PRESERVING_EDIT,
            };
        }

        if (decision === 'native') {
            return {
                eligible: false,
                reason: REASONS.WORKSPACE_EXIT,
            };
        }

        return {
            eligible: false,
            reason: REASONS.UNSUPPORTED_EMPTY_TASK,
        };
    }
}