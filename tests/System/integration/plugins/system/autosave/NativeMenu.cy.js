/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Native Menu Item draft acceptance', () => {
  let owner;
  let itemId;
  let originalTitle;
  let recoveredTitle;
  beforeEach(() => {
    owner = null;
    itemId = null;
    const suffix = `${Date.now()}-${Cypress._.random(10000, 99999)}`;
    const username = `autosave-menu-${suffix}`;
    originalTitle = `Autosave menu ${suffix}`;
    recoveredTitle = `Recovered menu ${suffix}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 8, params: '{"allowTourAutoStart":0}' })
      .then((id) => { owner = Number(id); });
    cy.doAdministratorLogin(username, 'test', false);
    cy.db_createMenuItem({ title: originalTitle, alias: `autosave-menu-${suffix}`, link: 'index.php?option=com_content&view=featured' })
      .then((id) => { itemId = Number(id); });
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.preserve') request.alias = 'preserve';
      if (task === 'draft.detect') request.alias = 'detect';
    });
  });
  afterEach(() => {
    if (owner) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${owner}`);
    if (originalTitle) cy.db_deleteMenuItem({ title: originalTitle });
    if (recoveredTitle) cy.db_deleteMenuItem({ title: recoveredTitle });
  });

  it('compares and restores selected text while native access and route stay independent', () => {
    cy.then(() => cy.visit(`/administrator/index.php?option=com_menus&task=item.edit&id=${itemId}`));
    cy.get('#item-form').should('be.visible');
    cy.window().should((win) => {
      expect(win.Joomla.getOptions('com_autosave.forms')).to.have.property('item-form');
      const config = win.Joomla.getOptions('com_autosave.forms')['item-form'];
      expect(config.available).to.equal(true);
      expect(config.fields).to.have.property('title');
      expect(config.fields).to.not.have.property('access');
      expect(config.fields).to.not.have.property('link');
    });
    cy.wait('@detect').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="clean"]').should('be.visible');
    cy.get('#jform_title').clear();
    cy.get('#jform_title').type(recoveredTitle);
    cy.wait('@preserve').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.reload();
    cy.wait('@detect');
    cy.get('#jform_title').should('have.value', originalTitle);
    cy.get('#jform_access').select('Special');
    cy.get('[data-draft-action="review"]').click();
    cy.get('[data-draft-comparison]').should('contain.text', recoveredTitle);
    cy.get('#jform_title').should('have.value', originalTitle);
    cy.get('[data-draft-action="restore"]').should('be.enabled').click();
    cy.wait('@preserve').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.get('#jform_title').should('have.value', recoveredTitle);
    cy.get('#jform_access option:selected').should('have.text', 'Special');
    cy.get('#jform_link').should('have.value', 'index.php?option=com_content&view=featured');
    cy.clickToolbarButton('Save & Close');
    cy.checkForSystemMessage('Menu item saved');
    cy.then(() => cy.task('queryDB', `SELECT title, access, link FROM #__menu WHERE id = ${itemId}`))
      .then((rows) => {
        expect(rows[0].title).to.equal(recoveredTitle);
        expect(Number(rows[0].access)).to.equal(3);
        expect(rows[0].link).to.equal('index.php?option=com_content&view=featured');
      });
    cy.then(() => cy.visit(`/administrator/index.php?option=com_menus&task=item.edit&id=${itemId}`));
    cy.wait('@detect').its('response.body.data.candidates').should('have.length', 0);
    cy.get('[data-draft-state="clean"]').should('be.visible');
  });
});
