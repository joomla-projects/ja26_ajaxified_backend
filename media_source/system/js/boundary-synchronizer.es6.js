/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const serializeBoundary = (boundaryName, payload) => {
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

    return html;
};

const applyBoundary = async (boundaryName, payload) => {
    const stream = document.body.streamAppendHTMLUnsafe();

    const writer = stream.getWriter();

    await writer.write(serializeBoundary(boundaryName, payload));

    await writer.close();

    return document.getElementById(boundaryName) || document.body;
};

export default class BoundarySynchronizer {
    /**
     * Synchronize a named boundary from a response snapshot.
     *
     * @param {ResponseSnapshot} snapshot
     * @param {string} boundaryName
     *
     * @returns {Promise<Element|null>}
     */
    static async synchronize(snapshot, boundaryName) {
        const boundary = snapshot.boundary(boundaryName);

        if (!boundary) {
            return null;
        }

        const boundaryRoot = await applyBoundary(
            boundaryName,
            boundary.payload
        );

        return boundaryRoot;
    }
}
