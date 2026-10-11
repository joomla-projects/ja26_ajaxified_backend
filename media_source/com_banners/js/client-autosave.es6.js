/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import ClientAutosaveController from '../src/client-autosave-controller.es6.js';

const clientAutosaveController = new ClientAutosaveController();
clientAutosaveController.start();

export default clientAutosaveController;
