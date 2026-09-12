describe('Test administrator article Custom Field filters', () => {
  let articleId;
  let fieldIds;

  const createFilterField = (field) => cy.db_createField(field).then((fieldId) => {
    fieldIds.push(fieldId);

    return fieldId;
  });

  beforeEach(() => {
    fieldIds = [];

    cy.doAdministratorLogin();

    cy.db_createArticle({ title: 'Custom Field Filter Test Article' }).then((article) => {
      articleId = article.id;
    });
  });

  afterEach(() => {
    if (fieldIds.length) {
      cy.task('queryDB', `DELETE FROM #__fields WHERE id IN (${fieldIds.join(',')})`);
    }

    cy.task('queryDB', `DELETE FROM #__workflow_associations WHERE item_id = ${articleId} AND extension = 'com_content.article'`);
    cy.task('queryDB', `DELETE FROM #__content WHERE id = ${articleId}`);
  });

  it('renders enabled option fields as native SearchTools controls and clears them', () => {
    createFilterField({
      title: 'Filter region',
      context: 'com_content.article',
      type: 'list',
      params: JSON.stringify({ show_in_admin_list_filter: 1 }),
      fieldparams: JSON.stringify({
        options: {
          options0: { name: 'India', value: 'india' },
          options1: { name: 'Japan', value: 'japan' },
        },
      }),
    }).then((fieldId) => {
      cy.visit('/administrator/index.php?option=com_content&view=articles&filter=');
      cy.get(`[name="filter[customfield_${fieldId}][]"]`).should('exist').select('india');
      cy.get(`[name="filter[customfield_${fieldId}][]"]`).should('have.value', 'india');
      cy.get('.js-stools-btn-clear').click();
      cy.get(`[name="filter[customfield_${fieldId}][]"]`).should('have.value', null);
    });
  });

  it('rejects a forged option without returning an unfiltered successful list', () => {
    createFilterField({
      title: 'Filter priority',
      context: 'com_content.article',
      type: 'radio',
      params: JSON.stringify({ show_in_admin_list_filter: 1 }),
      fieldparams: JSON.stringify({
        options: {
          options0: { name: 'High', value: 'high' },
        },
      }),
    }).then((fieldId) => {
      cy.visit(`/administrator/index.php?option=com_content&view=articles&filter[customfield_${fieldId}][]=forged`);
      cy.checkForSystemMessage('The submitted Custom Field filters are invalid.');
    });
  });

  it('keeps numeric-looking option tokens distinct through removal and refresh', () => {
    createFilterField({
      title: 'Filter code',
      context: 'com_content.article',
      type: 'list',
      params: JSON.stringify({ show_in_admin_list_filter: 1 }),
      fieldparams: JSON.stringify({
        options: {
          options0: { name: 'Zero', value: '0' },
          options1: { name: 'Leading zero', value: '01' },
          options2: { name: 'One', value: '1' },
        },
      }),
    }).then((fieldId) => {
      const selector = `[name="filter[customfield_${fieldId}][]"]`;
      const shouldHaveSelected = (expected) => cy.get(selector).find('option:selected').then(($options) => {
        expect([...$options].map((option) => option.value)).to.deep.equal(expected);
      });

      cy.visit('/administrator/index.php?option=com_content&view=articles&filter=');
      cy.get(selector).select('1');
      shouldHaveSelected(['1']);
      cy.reload();
      shouldHaveSelected(['1']);

      cy.get(selector).select(['01', '1']);
      shouldHaveSelected(['01', '1']);
      cy.get(selector).closest('joomla-field-fancy-select').find('.choices__item[data-value="1"] .choices__button_joomla').click();
      shouldHaveSelected(['01']);
      cy.reload();
      shouldHaveSelected(['01']);

      cy.get(selector).closest('joomla-field-fancy-select').find('.choices__item[data-value="01"] .choices__button_joomla').click();
      shouldHaveSelected([]);
      cy.reload();
      shouldHaveSelected([]);

      cy.get(selector).select('0');
      shouldHaveSelected(['0']);
      cy.reload();
      shouldHaveSelected(['0']);
    });
  });
});
