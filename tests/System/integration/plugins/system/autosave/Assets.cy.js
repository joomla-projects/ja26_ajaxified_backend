/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Published autosave asset registrations', () => {
  ['com_content', 'com_autosave', 'com_categories', 'com_languages', 'com_banners', 'com_contact', 'com_fields', 'com_finder', 'com_guidedtours', 'com_menus', 'com_modules', 'com_tags', 'com_templates', 'com_users', 'com_workflow'].forEach((component) => {
    it(`matches the source registration for ${component}, including import maps`, () => {
      cy.readFile(`media_source/${component}/joomla.asset.json`).then((source) => {
        cy.readFile(`media/${component}/joomla.asset.json`).should('deep.equal', source);
      });
    });
  });
});
