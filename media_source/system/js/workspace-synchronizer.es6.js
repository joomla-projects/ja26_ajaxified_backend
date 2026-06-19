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
    static synchronize(snapshot) {
        const { document, response } = snapshot;

        history.replaceState(
            history.state,
            '',
            response.url,
        );

        const detachedForm = document.querySelector('form[name="adminForm"]');
        const liveForm = window.document.querySelector('form[name="adminForm"]');

        if (liveForm && detachedForm) {
            liveForm.action = detachedForm.action;
        }

        const liveId = window.document.querySelector('[name="jform[id]"]');
        const responseId = document.querySelector('[name="jform[id]"]');

        if (liveId && responseId) {
            liveId.value = responseId.value;
        }

        const liveAliasField = window.document.querySelector(
            '[name="jform[alias]"]',
        );

        const responseAliasField = document.querySelector(
            '[name="jform[alias]"]',
        );

        if (liveAliasField && responseAliasField) {
            liveAliasField.value = responseAliasField.value;
        }

        const liveVersionField = window.document.querySelector(
            '[name="jform[version]"]',
        );

        const responseVersionField = document.querySelector(
            '[name="jform[version]"]',
        );

        if (liveVersionField && responseVersionField) {
            liveVersionField.value = responseVersionField.value;
        }
    }
}
