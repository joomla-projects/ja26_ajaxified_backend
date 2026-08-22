/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

export default class RuntimeOptionsSynchronizer {
    /**
     * Synchronize Joomla runtime options from a response snapshot.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {void}
     */
    static synchronize(snapshot) {
        if (!snapshot.options) {
            return;
        }

        Joomla.loadOptions(snapshot.options);
    }
}
