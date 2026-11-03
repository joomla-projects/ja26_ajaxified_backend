describe('Test in backend that the category form', () => {
  beforeEach(() => {
    cy.doAdministratorLogin();
    // Clear the filter
    cy.visit('/administrator/index.php?option=com_categories&extension=com_content&filter=');
  });
  afterEach(() => cy.db_deleteCategory({ title: 'Test category' }));

  it('can create a category', () => {
    cy.visit('/administrator/index.php?option=com_categories&task=category.add&extension=com_content');
    cy.get('#jform_title').should('exist').type('Test category');
    cy.clickToolbarButton('Save & Close');

    cy.checkForSystemMessage('Category saved.');
    cy.contains('Test category');
  });

  it('can change access level of a test category', () => {
    cy.db_createCategory({ title: 'Test category' }).then((id) => {
      cy.visit(`/administrator/index.php?option=com_categories&task=category.edit&id=${id}&extension=com_content`);
      cy.get('#jform_access').select('Special');
      cy.clickToolbarButton('Save & Close');

      cy.get('td').contains('Special').should('exist');
    });
  });

  it('check redirection to list view', () => {
    cy.visit('/administrator/index.php?option=com_categories&task=category.add&extension=com_content&filter[published]=');
    cy.intercept('index.php?option=com_categories&view=categories&extension=com_content').as('listview');
    cy.clickToolbarButton('Cancel');

    cy.wait('@listview');
  });
});

describe('Native Category draft protection', () => {
  let ids;
  let title;
  const remember = () => cy.window().then((win) => {
    const binding = win.Joomla.getOptions('com_autosave.forms')['item-form'];
    expect(binding.available).to.equal(true);
    ids.push(binding.draftId);
  });

  beforeEach(() => {
    ids = [];
    title = `Autosave category ${Date.now()}`;
    cy.doAdministratorLogin();
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.detect') request.alias = 'detectCategoryDraft';
      if (task === 'draft.preserve') request.alias = 'preserveCategoryDraft';
    });
  });

  afterEach(() => {
    ids.forEach((id) => cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE draft_id = '${id}'`));
    cy.db_deleteCategory({ title });
    cy.db_deleteCategory({ title: title + ' recovered' });
  });

  it('creates privately, resumes its own reload, and retires recovery on native Apply', () => {
    cy.visit('/administrator/index.php?option=com_categories&task=category.add&extension=com_content');
    remember();
    cy.wait('@detectCategoryDraft').its('response.body.data.candidates').should('have.length', 0);
    cy.get('#jform_title').type(title);
    cy.wait('@preserveCategoryDraft').its('response.body.success').should('equal', true);
    cy.task('queryDB', `SELECT COUNT(*) AS total FROM #__categories WHERE title = '${title}'`)
      .then((rows) => expect(Number(rows[0].total)).to.equal(0));
    cy.reload();
    remember();
    cy.wait('@detectCategoryDraft').its('response.body.data.candidates').should('have.length', 1);
    cy.get('#jform_title').should('have.value', '');
    cy.get('[data-draft-recovery]').should('be.visible');
    cy.get('[data-draft-action="review"]').click();
    cy.get('[data-draft-action="restore"]').should('be.enabled').click();
    cy.get('#jform_title').should('have.value', title);
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.clickToolbarButton('Save');
    cy.checkForSystemMessage('Category saved.');
    remember();
    cy.wait('@detectCategoryDraft').its('response.body.data.candidates').should('have.length', 0);
    cy.get('[data-draft-recovery]').should('not.be.visible');
    cy.get('#toolbar [data-draft-ui]').should('be.visible');
    cy.get('#jform_title').should('have.value', title);
    cy.task('queryDB', `SELECT payload FROM #__autosave_drafts WHERE draft_id IN ('${ids.slice(0, 2).join("','")}')`)
      .then((rows) => rows.forEach((row) => expect(row.payload).to.equal(null)));
  });

  it('keeps saved item Category values and hierarchy unchanged until explicit recovery and native Save', () => {
    cy.db_createCategory({ title }).then((id) => {
      let canonical;
      cy.task('queryDB', `SELECT title, parent_id, lft, rgt, path FROM #__categories WHERE id = ${id}`)
        .then((rows) => { [canonical] = rows; });
      cy.visit(`/administrator/index.php?option=com_categories&task=category.edit&id=${id}&extension=com_content`);
      remember();
      cy.wait('@detectCategoryDraft');
      cy.get('#jform_title').clear().type(title + ' recovered');
      cy.get('#jform_description_ifr').its('0.contentDocument.body').should('not.be.empty')
        .then((body) => cy.wrap(body).clear().type(title + ' protected body'));
      cy.get('[data-draft-state="preserved"]').should('be.visible');
      cy.task('queryDB', `SELECT title, parent_id, lft, rgt, path FROM #__categories WHERE id = ${id}`)
        .then((rows) => expect(rows[0]).to.deep.equal(canonical));
      cy.reload();
      remember();
      cy.wait('@detectCategoryDraft');
      cy.get('#jform_title').should('have.value', title);
      cy.get('#jform_description_ifr').its('0.contentDocument.body').should('not.contain.text', title + ' protected body');
      cy.get('[data-draft-recovery]').should('be.visible');
      cy.get('[data-draft-action="review"]').click();
      cy.get('[data-draft-action="restore"]').should('be.enabled').click();
      cy.get('#jform_title').should('have.value', title + ' recovered');
      cy.get('#jform_description_ifr').its('0.contentDocument.body').should('contain.text', title + ' protected body');
      cy.get('[data-draft-state="preserved"]').should('be.visible');
      cy.clickToolbarButton('Save & Close');
      cy.checkForSystemMessage('Category saved.');
      cy.visit(`/administrator/index.php?option=com_categories&task=category.edit&id=${id}&extension=com_content`);
      remember();
      cy.wait('@detectCategoryDraft').its('response.body.data.candidates').should('have.length', 0);
      cy.get('[data-draft-recovery]').should('not.be.visible');
      cy.get('#jform_title').should('have.value', title + ' recovered');
      cy.get('#jform_description_ifr').its('0.contentDocument.body').should('contain.text', title + ' protected body');
    });
  });
});
