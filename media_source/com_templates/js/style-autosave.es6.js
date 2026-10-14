/**
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */
import mountDraftForm from '../../com_autosave/js/draft-form.es6.js';
const start = () => {
  const configuration = Joomla.getOptions('com_templates.autosave.style');
  if (!configuration) return;
  const form = document.getElementById(configuration.formId);
  if (form) mountDraftForm(form, configuration);
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();
