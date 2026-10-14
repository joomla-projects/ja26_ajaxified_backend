/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

for (const component of ['com_content', 'com_autosave', 'com_categories', 'com_languages', 'com_banners', 'com_contact', 'com_fields', 'com_finder', 'com_guidedtours', 'com_menus', 'com_modules', 'com_newsfeeds', 'com_redirect', 'com_tags', 'com_templates', 'com_users', 'com_workflow']) {
  test(`published ${component} registration matches the built source, including import maps`, async () => {
    const [source, published] = await Promise.all([
      readFile(`media_source/${component}/joomla.asset.json`, 'utf8'),
      readFile(`media/${component}/joomla.asset.json`, 'utf8'),
    ]);
    assert.deepEqual(JSON.parse(published), JSON.parse(source),
      'Build the component with Joomla copy and js tasks; a js-only build leaves obsolete registrations.');
  });
}
