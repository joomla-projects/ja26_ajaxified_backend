/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import mountDraftForm from './draft-form.es6.js';

const mounted = new WeakSet();
const start = () => {
  const configurations = Joomla.getOptions('com_autosave.forms', {});
  if (!configurations || typeof configurations !== 'object' || Array.isArray(configurations)) return;
  const elements = Array.from(document.querySelectorAll('[id]'));
  Object.entries(configurations).forEach(([id, configuration]) => {
    if (!configuration || configuration.formId !== id) return;
    const matches = elements.filter((element) => element.getAttribute('id') === id);
    const form = matches.length === 1 ? matches[0] : null;
    if (!form || form.tagName !== 'FORM' || mounted.has(form)) return;
    mounted.add(form);
    try {
      mountDraftForm(form, configuration);
    } catch (error) {
      // Continue mounting the remaining forms.
    }
  });
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();
