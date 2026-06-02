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

const CONTEXT_PRESERVING_EDIT_ACTIONS = [
    'apply',
    'save2copy',
];

const WORKSPACE_EXIT_ACTIONS = [
    'save',
    'cancel',
    'save2new',
];

const LIST_ACTIONS = [
    'publish',
    'unpublish',
    'archive',
    'trash',
    'delete',
    'checkin',
    'saveorder',
    'batch',
    'runTransition',
];

/**
 * Determine whether a submission has no normalized action.
 *
 * @param {SubmissionContext} context
 *
 * @returns {boolean}
 */
const isEmptyTaskSubmission = (context) => !context.action;

/**
 * Determine whether a submission preserves the current edit workspace.
 *
 * @param {string|null} action
 *
 * @returns {boolean}
 */
const isContextPreservingEdit = (action) => CONTEXT_PRESERVING_EDIT_ACTIONS.includes(action);

/**
 * Determine whether a submission exits the current workspace.
 *
 * @param {string|null} action
 *
 * @returns {boolean}
 */
const isWorkspaceExit = (action) => WORKSPACE_EXIT_ACTIONS.includes(action);

/**
 * Determine whether a submission performs a list action.
 *
 * @param {string|null} action
 *
 * @returns {boolean}
 */
const isListAction = (action) => LIST_ACTIONS.includes(action);

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

        if (isContextPreservingEdit(context.action)) {
            return {
                eligible: true,
                reason: REASONS.CONTEXT_PRESERVING_EDIT,
            };
        }

        if (isListAction(context.action)) {
            return {
                eligible: true,
                reason: REASONS.LIST_ACTION,
            };
        }

        if (isWorkspaceExit(context.action)) {
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

