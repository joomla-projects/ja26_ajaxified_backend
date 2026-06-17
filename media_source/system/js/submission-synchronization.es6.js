/**
 * @copyright   (C) Open Source Matters, Inc.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
         * Phase 2:
         * Resolve the canonical HTML response.
         */
        const html = await response.text();

        /*
         * Phase 3:
         * Create a detached representation of the
         * server-rendered document.
         */
        const detachedDocument = this.createDetachedDocument(html);

        SubmissionSynchronization.synchronizeWorkspaceIdentity({
            document: detachedDocument,
            response,
        });

        this.extractMessages(detachedDocument);

        const toolbar = this.extractBoundary(
            detachedDocument,
            'toolbar'
        );

        if (toolbar) {
            await this.applyBoundary(
                'toolbar',
                toolbar.payload
            );
        }



        /*
         * Phase 4:
         * Discover server-declared synchronization boundaries.
         */
        const messages = this.extractBoundary(
            detachedDocument,
            'messages'
        );

        /*if (messages) {
            await this.applyBoundary(
                'messages',
                messages.payload
            );
        }*/

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
     * Create a detached HTML document.
     *
     * @param {string} html
     *
     * @returns {HTMLDocument}
     */
    static createDetachedDocument(html) {
        return new DOMParser().parseFromString(
            html,
            'text/html'
        );
    }

    /**
     * Extract Joomla messages from the detached document
     * and replay Joomla's native renderer.
     *
     * @param {HTMLDocument} detachedDocument
     *
     * @returns {Array|null}
     */
    static extractMessages(detachedDocument) {
        const script = detachedDocument.querySelector(
            'script.joomla-script-options'
        );

        if (!script) {
            return null;
        }

        try {
            const options = JSON.parse(script.textContent);

            const messages = options['joomla.messages'];

            if (!messages) {
                return null;
            }

            /*
             * Remove any currently visible alerts
             * before replaying the new ones.
             */
            document
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
        } catch (error) {
            console.error(
                '[Ajaxified Messages] Failed to parse script options',
                error
            );

            return null;
        }
    }

    /**
    * Extract a server-declared synchronization boundary.
    *
    * @param {HTMLDocument} document
    * @param {string} boundaryName
    *
     * @returns {Object|null}
     */
    static extractBoundary(document, boundaryName) {
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_PROCESSING_INSTRUCTION
        );

        let startNode = null;
        let endNode = null;
        let node;

        /*
         * Locate the start processing instruction.
         */
        while ((node = walker.nextNode())) {
            if (
                node.target === 'start'
                && node.data.includes(`name="${boundaryName}"`)
            ) {
                startNode = node;

                break;
            }
        }

        if (!startNode) {
            return null;
        }

        /*
         * Continue walking until the matching end marker.
         */
        while ((node = walker.nextNode())) {
            if (node.target === 'end') {
                endNode = node;

                break;
            }
        }

        if (!endNode) {
            return null;
        }

        /*
         * Extract everything between the boundaries.
         */
        const payload = [];

        let current = startNode.nextSibling;

        while (current && current !== endNode) {
            payload.push(current);

            current = current.nextSibling;
        }

        return {
            startNode,
            endNode,
            payload,
        };
    }

    /**
     * Apply a synchronization boundary using DPU.
     *
     * @param {string} boundaryName
     * @param {Node[]} payload
     *
     * @returns {Promise<void>}
     */
    static async applyBoundary(boundaryName, payload) {
        let html = `<template for="${boundaryName}">`;

        payload.forEach((node) => {
            const clone = node.cloneNode(true);

            if (clone.nodeType === Node.ELEMENT_NODE) {
                html += clone.outerHTML;
            } else {
                html += clone.textContent;
            }
        });

        html += '</template>';

        const stream = document.body.streamAppendHTMLUnsafe();

        const writer = stream.getWriter();

        await writer.write(html);

        await writer.close();
    }

    /**
 * Synchronize workspace identity.
 *
 * @param {Document} document
 * @param {Response} response
 */
    static synchronizeWorkspaceIdentity({
        document,
        response,
    }) {
        history.replaceState(
            history.state,
            '',
            response.url,
        );

        const detachedForm = document.querySelector('form[name="adminForm"]');
        const liveForm = window.document.querySelector('form[name="adminForm"]');

        liveForm.action = detachedForm.action;

        const liveId = window.document.querySelector('[name="jform[id]"]');
        const responseId = document.querySelector('[name="jform[id]"]');

        liveId.value = responseId.value;

        const liveAliasField = window.document.querySelector(
            '[name="jform[alias]"]',
        );

        const responseAliasField = document.querySelector(
            '[name="jform[alias]"]',
        );

        liveAliasField.value = responseAliasField.value;

        const liveVersionField = window.document.querySelector(
            '[name="jform[version]"]',
        );

        const responseVersionField = document.querySelector(
            '[name="jform[version]"]',
        );

        liveVersionField.value = responseVersionField.value;
    }
}
