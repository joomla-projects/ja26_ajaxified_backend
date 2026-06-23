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

        const strategy = this.resolveSynchronizationStrategy(context);

        await this.synchronizeAssets(snapshot);

        this.synchronizeWorkspace(snapshot, strategy);

        this.synchronizeRuntimeOptions(snapshot);

        await this.synchronizeBoundaries(snapshot, strategy);

        this.synchronizeMessages(snapshot);
    }

    /**
     * Resolve the synchronization strategy based on the context.
     *
     * @param {SubmissionContext} context
     *
     * @returns {{synchronizeControls: boolean, boundaries: string[]}}
     */
    static resolveSynchronizationStrategy(context) {
        if (!context.action) {
            return {
                synchronizeControls: false,
                boundaries: [
                    'toolbar',
                    'j-main-container',
                ],
            };
        }

        const policy = this.getSynchronizationPolicy();
        const actionPolicy = policy[context.action];

        if (!actionPolicy) {
            return this.getDefaultSynchronizationStrategy();
        }

        return {
            synchronizeControls: actionPolicy.synchronizeControls !== false,
            boundaries: actionPolicy.boundaries || ['toolbar'],
        };
    }

    /**
     * Retrieve the default synchronization strategy.
     *
     * @returns {{synchronizeControls: boolean, boundaries: string[]}}
     */
    static getDefaultSynchronizationStrategy() {
        return {
            synchronizeControls: true,
            boundaries: [
                'toolbar',
            ],
        };
    }

    /**
     * Retrieve the server-defined synchronization policy.
     *
     * @returns {Object}
     */
    static getSynchronizationPolicy() {
        return Joomla.getOptions('submission-synchronization', {});
    }

    /**
     * Synchronize server-declared assets.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {Promise<void>}
     */
    static async synchronizeAssets(snapshot) {
        await AssetSynchronizer.synchronize(snapshot);
    }

    /**
     * Synchronize the workspace based on the strategy.
     *
     * @param {ResponseSnapshot} snapshot
     * @param {{synchronizeControls: boolean}} strategy
     *
     * @returns {void}
     */
    static synchronizeWorkspace(snapshot, strategy) {
        WorkspaceSynchronizer.synchronize(snapshot, strategy);
    }

    /**
     * Synchronize Joomla runtime options.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {void}
     */
    static synchronizeRuntimeOptions(snapshot) {
        RuntimeOptionsSynchronizer.synchronize(snapshot);
    }

    /**
     * Synchronize response boundaries based on the strategy.
     *
     * @param {ResponseSnapshot} snapshot
     * @param {{boundaries: string[]}} strategy
     *
     * @returns {Promise<void>}
     */
    static async synchronizeBoundaries(snapshot, strategy) {
        for (const boundaryName of strategy.boundaries) {
            const boundaryRoot = await BoundarySynchronizer.synchronize(
                snapshot,
                boundaryName
            );

            if (boundaryRoot) {
                RuntimeLifecycleManager.activate(boundaryRoot);
            }
        }
    }

    /**
     * Replay messages.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {void}
     */
    static synchronizeMessages(snapshot) {
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
