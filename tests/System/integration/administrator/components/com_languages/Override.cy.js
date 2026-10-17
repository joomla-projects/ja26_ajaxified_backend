/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Native Language Override draft protection', () => {
  let ids;
  let key;
  const open = (source = '', client = 0) => cy.visit(
    `/administrator/index.php?option=com_languages&view=override&layout=edit&id=${source}&filter_language=en-GB&filter_client=${client}&language_client=en-GB${client}`,
  );
  const remember = () => cy.window().then((win) => {
    const binding = win.Joomla.getOptions('com_autosave.forms')['override-form'];
    expect(binding.available).to.equal(true);
    ids.push(binding.draftId);
  });

  beforeEach(() => {
    ids = [];
    key = `PLG_SYSTEM_AUTOSAVE_TEST_${Date.now()}_${Cypress._.random(10000, 99999)}`;
    cy.doAdministratorLogin();
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.detect') request.alias = 'detectOverrideDraft';
      if (task === 'draft.preserve') request.alias = 'preserveOverrideDraft';
    });
  });

  afterEach(() => {
    ids.forEach((id) => cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE draft_id = '${id}'`));
    cy.window().then((win) => {
      const token = win.Joomla.getOptions('csrf.token');
      [0, 1].forEach((client) => cy.request({
        method: 'POST',
        url: `/administrator/index.php?option=com_languages&task=overrides.delete&language_client=en-GB${client}`,
        form: true,
        body: { [token]: '1', cid: [key, key + '_RENAMED'] },
      }));
    });
  });

  it('recovers its checkbox and keeps the rendered client scope when another tab changes filters', () => {
    open();
    remember();
    cy.wait('@detectOverrideDraft').its('response.body.data.candidates').should('have.length', 0);
    cy.get('#jform_key').type(key);
    cy.get('#jform_override').type('Protected native override');
    cy.get('#jform_both').check();
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.reload();
    remember();
    cy.wait('@detectOverrideDraft').its('response.body.data.candidates').should('have.length', 1);
    cy.get('#jform_key').should('have.value', '');
    cy.get('[data-draft-action="review"]').click();
    cy.get('[data-draft-action="restore"]').should('be.enabled').click();
    cy.get('#jform_key').should('have.value', key);
    cy.get('#jform_both').should('be.checked');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.request('/administrator/index.php?option=com_languages&view=overrides&language_client=en-GB1');
    cy.clickToolbarButton('Save');
    cy.checkForSystemMessage('Language Override was saved.');
    remember();
    cy.wait('@detectOverrideDraft').its('response.body.data.candidates').should('have.length', 0);
    cy.get('[name="filter_client"]').should('have.value', '0');
    cy.get('[data-draft-recovery]').should('not.be.visible');
    open(key, 1);
    remember();
    cy.wait('@detectOverrideDraft');
    cy.get('#jform_override').should('have.value', 'Protected native override');
    open(key, 0);
    remember();
    cy.wait('@detectOverrideDraft');
    cy.get('#jform_override').should('have.value', 'Protected native override');
  });

  it('keeps canonical values on reopening and retires a recovered source after a native key rename', () => {
    open();
    remember();
    cy.wait('@detectOverrideDraft');
    cy.get('#jform_key').type(key);
    cy.get('#jform_override').type('Canonical override');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.clickToolbarButton('Save & Close');
    cy.checkForSystemMessage('Language Override was saved.');
    open(key);
    remember();
    cy.wait('@detectOverrideDraft');
    cy.get('#jform_key').clear().type(key + '_RENAMED');
    cy.get('#jform_override').clear().type('Unfinished override');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.reload();
    remember();
    cy.wait('@detectOverrideDraft');
    cy.get('#jform_key').should('have.value', key);
    cy.get('#jform_override').should('have.value', 'Canonical override');
    cy.get('[data-draft-action="review"]').click();
    cy.get('[data-draft-action="restore"]').should('be.enabled').click();
    cy.get('#jform_key').should('have.value', key + '_RENAMED');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.clickToolbarButton('Save');
    cy.checkForSystemMessage('Language Override was saved.');
    remember();
    cy.wait('@detectOverrideDraft').its('response.body.data.candidates').should('have.length', 0);
    cy.get('[data-draft-recovery]').should('not.be.visible');
    cy.get('#jform_key').should('have.value', key + '_RENAMED');
    cy.get('#jform_override').should('have.value', 'Unfinished override');
  });
});
