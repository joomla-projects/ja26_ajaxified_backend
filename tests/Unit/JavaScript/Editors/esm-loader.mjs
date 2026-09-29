/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const aliases = {
  codemirror: new URL('../../../../media_source/plg_editors_codemirror/js/codemirror.es6.js', import.meta.url).href,
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
    && (url.includes('/media_source/system/js/editors/')
      || url.includes('/media_source/plg_editors_tinymce/src/')
      || url.includes('/media_source/plg_editors_codemirror/js/codemirror.es6.js')
      || url.includes('/media_source/plg_editors_codemirror/src/')
      || url.includes('/media_source/plg_editors_none/src/')
      || url.includes('/tests/Unit/JavaScript/Editors/'))
    && url.endsWith('.js')) {
    return {
      format: 'module',
      shortCircuit: true,
      source: await readFile(fileURLToPath(url), 'utf8'),
    };
  }

  return nextLoad(url, context);
}
