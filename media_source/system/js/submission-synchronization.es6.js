/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

import AssetSynchronizer from './asset-synchronizer.es6.js';
import BoundarySynchronizer from './boundary-synchronizer.es6.js';
import ResponseSnapshot from './response-snapshot.es6.js';
import RuntimeOptionsSynchronizer from './runtime-options-synchronizer.es6.js';
import RuntimeLifecycleManager from './runtime-lifecycle-manager.es6.js';
import WorkspaceSynchronizer from './workspace-synchronizer.es6.js';

export default class SubmissionSynchronization {
    /**
     * Apply server-declared updates from a response.
     *
     * @param {Response} response
     * @param {SubmissionContext} context
     *
     * @returns {Promise<void>}
     */
    static async synchronize({ response, context }) {
        /*
         * Phase 1:
         * Determine whether the response remains within
         * the current synchronization workspace.
         */
        if (!this.isSynchronizableResponse(response, context)) {
            this.performNavigation(response);

            return;
        }

        /*
         * Phase 3:
         * Interpret the response into an immutable snapshot.
        */

        const snapshot = await ResponseSnapshot.from(response);

        await AssetSynchronizer.synchronize(snapshot);

        WorkspaceSynchronizer.synchronize(snapshot);

        RuntimeOptionsSynchronizer.synchronize(snapshot);

        const boundaryRoot = await BoundarySynchronizer.synchronize(
            snapshot,
            'toolbar'
        );

        if (boundaryRoot) {
            RuntimeLifecycleManager.activate(boundaryRoot);
        }

        this.replayMessages(snapshot.messages);
    }

    /**
     * Determine whether the response can be synchronized
     * within the current workspace.
     *
     * @param {Response} response
     * @param {SubmissionContext} context
     *
     * @returns {boolean}
     */
    static isSynchronizableResponse(response, context) {
        return true;
    }

    /**
     * Perform native navigation.
     *
     * @param {Response} response
     */
    static performNavigation(response) {
        window.location.href = response.url;
    }

    /**
     * Replay Joomla messages using Joomla's native renderer.
     *
     * @param {Array|null} messages
     *
     * @returns {Array|null}
     */
    static replayMessages(messages) {
        if (!messages) {
            return null;
        }

        /*
         * Remove any currently visible alerts
         * before replaying the new ones.
         */
        window.document
            .querySelectorAll(
                '#system-message-container joomla-alert'
            )
            .forEach((alert) => {
                alert.remove();
            });

        /*
         * Replay Joomla's native rendering loop.
         */
        messages.forEach((message) => {
            Joomla.renderMessages(
                message,
                undefined,
                true,
                undefined
            );
        });

        return messages;
    }
}
