/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Published autosave asset registration', () => {
  it('matches the source autosave registration', () => {
    cy.readFile('media_source/com_autosave/joomla.asset.json').then((source) => {
      cy.readFile('media/com_autosave/joomla.asset.json').should('deep.equal', source);
    });
  });
});
