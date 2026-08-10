/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AssetSynchronizer from './asset-synchronizer.es6.js';

/**
 * Immutable representation of a server response.
 *
 * A ResponseSnapshot captures the canonical response HTML together with
 * its detached document representation. It does not mutate the live
 * document or perform any synchronization logic.
 */
export default class ResponseSnapshot {
    /**
     * @param {Object} options
     * @param {Response} options.response
     * @param {string} options.html
     * @param {HTMLDocument} options.document
     * @param {Object|null} options.options
     * @param {Object} options.assets
     */
    constructor({
        response,
        html,
        document,
        options,
        assets,
    }) {
        this.response = response;
        this.html = html;
        this.document = document;
        this.options = options;
        this.assets = assets;

        Object.freeze(this);
    }

    /**
     * Create a snapshot from a Response.
     *
     * @param {Response} response
     *
     * @returns {Promise<ResponseSnapshot>}
     */
    static async from(response) {
        const html = await response.text();

        const document = new DOMParser().parseFromString(
            html,
            'text/html',
        );

        return new ResponseSnapshot({
            response,
            html,
            document,
            options: ResponseSnapshot.optionsFrom(document),
            assets: AssetSynchronizer.collect(document),
        });
    }

    /**
     * Extract Joomla script options.
     *
     * @param {HTMLDocument} document
     *
     * @returns {Object|null}
     */
    static optionsFrom(document) {
        const script = document.querySelector(
            'script.joomla-script-options'
        );

        if (!script) {
            return null;
        }

        try {
            return JSON.parse(script.textContent);
        } catch {
            return null;
        }
    }

    /**
     * Joomla messages declared by the response.
     *
     * @returns {Array|null}
     */
    get messages() {
        return this.options?.['joomla.messages'] ?? null;
    }

    /**
     * Extract a server-declared synchronization boundary.
     *
     * @param {string} boundaryName
     *
     * @returns {Object|null}
     */
    boundary(boundaryName) {
        const walker = this.document.createTreeWalker(
            this.document.body,
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
}
