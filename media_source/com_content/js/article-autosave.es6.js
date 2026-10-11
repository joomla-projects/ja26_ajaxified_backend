/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import ArticleAutosaveController from '../src/article-autosave-controller.es6.js';

const articleAutosaveController = new ArticleAutosaveController();
articleAutosaveController.start();

export default articleAutosaveController;
