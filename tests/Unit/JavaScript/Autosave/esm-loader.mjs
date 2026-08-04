/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

export async function load(url, context, nextLoad) {
  if (url.startsWith('file:')
    && (url.includes('/media_source/com_autosave/')
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
