describe('Test the update retrieval logic', () => {
  beforeEach(() => {
    cy.doAdministratorLogin();
  });

  afterEach(() => {
    cy.db_setValidTufRoot();
  });

  it('Can fetch available updates with valid metadata', () => {
    cy.db_setValidTufRoot();

    cy.visit('/administrator/index.php?option=com_joomlaupdate');

    // The update layout omits the toolbar button; exercise the same native action in either layout.
    cy.window().then((win) => {
      const token = win.Joomla.getOptions('csrf.token');
      expect(token).to.be.a('string').and.not.be.empty;
      cy.visit(`/administrator/index.php?option=com_joomlaupdate&task=update.purge&${token}=1`);
    });

    cy.checkForSystemMessage('Checked for updates.');
  });

  it('Receives error fetching available updates with invalid metadata', () => {
    cy.db_setInvalidTufRoot();

    cy.visit('/administrator/index.php?option=com_joomlaupdate');

    cy.get('#confirmButton').click();

    cy.checkForSystemMessage('Update not possible because the offered update does not have enough signatures.');
  });
});
