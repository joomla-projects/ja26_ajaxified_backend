/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Parse route parameters from a form action URL.
 *
 * @param {string} actionUrl
 *
 * @returns {Object.<string, string>}
 */
const parseRouteParams = (actionUrl) => {
    const params = {};

    try {
        const url = new URL(actionUrl, window.location.origin);

        url.searchParams.forEach((value, key) => {
            params[key] = value;
        });
    } catch (error) {
        // Ignore malformed URLs.
    }

    return params;
};

/**
 * Extract the raw task value from a form.
 *
 * @param {HTMLFormElement} form
 *
 * @returns {string}
 */
const extractTask = (form) => form.elements.task?.value || '';

/**
 * Normalize a Joomla task string.
 *
 * Example:
 *
 * article.apply
 *
 * becomes:
 *
 * {
 *   controller: 'article',
 *   action: 'apply'
 * }
 *
 * @param {string} rawTask
 *
 * @returns {{controller: string|null, action: string|null}}
 */
const normalizeTask = (rawTask) => {
    if (!rawTask) {
        return {
            controller: null,
            action: null,
        };
    }

    const parts = rawTask.split('.');

    if (parts.length < 2) {
        return {
            controller: null,
            action: rawTask,
        };
    }

    return {
        controller: parts.slice(0, -1).join('.'),
        action: parts[parts.length - 1],
    };
};

/**
 * Normalized representation of a Joomla administrator submission.
 */
export default class SubmissionContext {
    /**
     * Create a SubmissionContext from a submit event.
     *
     * @param {SubmitEvent} event
     *
     * @returns {SubmissionContext|null}
     */
    static fromSubmitEvent(event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return null;
        }

        return new SubmissionContext(form);
    }

    /**
     * Constructor.
     *
     * Prefer SubmissionContext.fromSubmitEvent()
     * when creating instances.
     *
     * @param {HTMLFormElement} form
     */
    constructor(form) {
        if (!(form instanceof HTMLFormElement)) {
            throw new TypeError('Expected HTMLFormElement');
        }

        const actionUrl = form.getAttribute('action') || '';
        const routeParams = parseRouteParams(actionUrl);
        const rawTask = extractTask(form);
        const normalizedTask = normalizeTask(rawTask);

        this._form = form;

        this._actionUrl = actionUrl;
        this._routeParams = routeParams;

        this._component = routeParams.option || null;
        this._view = routeParams.view || null;
        this._layout = routeParams.layout || null;

        this._rawTask = rawTask;
        this._controller = normalizedTask.controller;
        this._action = normalizedTask.action;
    }

    get form() {
        return this._form;
    }

    get actionUrl() {
        return this._actionUrl;
    }

    get routeParams() {
        return this._routeParams;
    }

    get component() {
        return this._component;
    }

    get view() {
        return this._view;
    }

    get layout() {
        return this._layout;
    }

    get rawTask() {
        return this._rawTask;
    }

    get controller() {
        return this._controller;
    }

    get action() {
        return this._action;
    }
}
