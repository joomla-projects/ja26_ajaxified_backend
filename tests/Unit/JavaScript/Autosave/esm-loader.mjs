/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const aliases = {
  'com_autosave.runtime': new URL('../../../../media_source/com_autosave/js/runtime.es6.js', import.meta.url).href,
  'editor-api': new URL('../../../../media_source/system/js/editors/editor-api.es6.js', import.meta.url).href,
  'editor-decorator': new URL('../../../../media_source/system/js/editors/editor-decorator.es6.js', import.meta.url).href,
};

export async function resolve(specifier, context, nextResolve) {
  if (aliases[specifier]) {
    return {
      shortCircuit: true,
      url: aliases[specifier],
    };
  }

  return nextResolve(specifier, context);
}

export async function load(url, context, nextLoad) {
  if (url.startsWith('file:')
    && (url.includes('/media_source/com_autosave/')
      || url.includes('/media_source/com_content/')
      || url.includes('/media_source/system/js/editors/')
      || url.includes('/tests/Unit/JavaScript/Autosave/'))
    && url.endsWith('.js')) {
    return {
      format: 'module',
      shortCircuit: true,
      source: await readFile(fileURLToPath(url), 'utf8'),
    };
  }

  return nextLoad(url, context);
}
