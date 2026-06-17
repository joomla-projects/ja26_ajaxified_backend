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
        console.log('SubmissionSynchronization invoked');

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

        console.log(
            html.includes('<?start')
        );

        console.log(
            html.includes('<?end')
        );

        console.log(
            html.indexOf('<?start')
        );

        console.log(
            html.substring(
                html.indexOf('system-message-container'),
                html.indexOf('system-message-container') + 500
            )
        );

        console.log('Response HTML length');
        console.log(html.length);

        /*
         * Phase 3:
         * Create a detached representation of the
         * server-rendered document.
         */
        const detachedDocument = this.createDetachedDocument(html);

        console.log('Detached document created');
        console.log(detachedDocument);

        this.extractMessages(detachedDocument);

        const toolbar = this.extractBoundary(
            detachedDocument,
            'toolbar'
        );

        console.log('Toolbar boundary extraction');

        console.log(toolbar);

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

        console.log('Messages boundary extraction');

        console.log(messages);

        /*if (messages) {
            await this.applyBoundary(
                'messages',
                messages.payload
            );
        }*/

        /*
         * Future phases:
         *
         * Toolbar extraction
         * Template generation
         * DPU reconciliation
         */
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
        console.log('Synchronizable response check');

        console.log(window.location.href);
        console.log(response.url);

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
            console.log(
                '[Ajaxified Messages] No script options found'
            );

            return null;
        }

        console.log(
            '[Ajaxified Messages] Script options found'
        );

        try {
            const options = JSON.parse(script.textContent);

            const messages = options['joomla.messages'];

            if (!messages) {
                console.log(
                    '[Ajaxified Messages] No messages found'
                );

                return null;
            }

            console.log(
                '[Ajaxified Messages] Replaying messages:',
                messages
            );

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
        console.log(`Searching boundary: ${boundaryName}`);

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
            console.log({
                nodeType: node.nodeType,
                nodeName: node.nodeName,
                target: node.target,
                data: node.data,
                value: node.nodeValue,
            });

            if (
                node.target === 'start'
                && node.data.includes(`name="${boundaryName}"`)
            ) {
                startNode = node;

                break;
            }
        }

        if (!startNode) {
            console.log(`Boundary not found: ${boundaryName}`);

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

        console.log('Boundary markers');

        console.log({
            startNode,
            endNode,
        });

        if (!endNode) {
            console.log(`End marker missing: ${boundaryName}`);

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

        console.log('Extracted payload');

        console.log(payload);

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
        console.log(`Applying boundary: ${boundaryName}`);

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

        console.log('Generated template HTML');

        console.log(html);

        const stream = document.body.streamAppendHTMLUnsafe();

        const writer = stream.getWriter();

        await writer.write(html);

        await writer.close();

        console.log('Boundary streamed');

        console.log(boundaryName);
    }
}