describe('Test in backend that the article form', () => {
  beforeEach(() => {
    cy.doAdministratorLogin();
    // Clear the filter
    cy.visit('/administrator/index.php?option=com_content&filter=');
  });
  afterEach(() => cy.task('queryDB', "DELETE FROM #__content WHERE title = 'Test article'"));

  it('can create an article', () => {
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    cy.get('#jform_title').clear().type('Test article');
    cy.clickToolbarButton('Save & Close');

    cy.checkForSystemMessage('Article saved.');
    cy.contains('Test article');
  });

  it('can change access level of a test article', () => {
    cy.db_createArticle({ title: 'Test article' }).then((article) => {
      cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${article.id}`);
      cy.get('#jform_access').select('Special');
      cy.clickToolbarButton('Save & Close');

      cy.get('td').contains('Special').should('exist');
    });
  });

  it('check redirection to list view', () => {
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    cy.intercept('index.php?option=com_content&view=articles').as('listview');
    cy.clickToolbarButton('Cancel');

    cy.wait('@listview');
  });
});

describe('Native Article draft protection', () => {
  let draftIds;
  const rememberInstance = () => cy.window().then((win) => {
    const binding = win.Joomla.getOptions('com_content.autosave.article');
    expect(binding.available).to.equal(true);
    expect(binding.draftId).to.match(/^[a-f0-9]{32}$/);
    draftIds.push(binding.draftId);
  });

  beforeEach(() => {
    draftIds = [];
    cy.doAdministratorLogin();
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.preserve') request.alias = 'preserveDraft';
      if (task === 'draft.detect') request.alias = 'detectDraft';
    });
  });

  afterEach(() => {
    draftIds.forEach((id) => cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE draft_id = '${id}'`));
    cy.task('queryDB', "DELETE FROM #__content WHERE title IN ('Phase 2 autosave fixture', 'Phase 2 recovered title')");
  });

  it('starts detection and presents compact readiness without exposed retention paragraphs', () => {
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      if (new URL(request.url).searchParams.get('task') === 'draft.detect') {
        request.alias = 'detectDraft';
        request.continue((reply) => reply.setDelay(500));
      }
    });
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.get('[data-draft-state="detecting"]').should('be.visible')
      .find('.icon-spinner.icon-spin').should('exist');
    cy.wait('@detectDraft').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="clean"]').should('be.visible').and('contain.text', 'Autosave ready');
    cy.get('[data-draft-status]').should('have.attr', 'aria-busy', 'false');
    cy.get('[data-draft-partial]').should('not.be.visible');
    cy.get('#toolbar').find('[data-draft-ui]').should('be.visible');
    cy.get('[data-draft-details], [data-draft-time]').should('not.exist');
    cy.get('[data-draft-status]').should('have.attr', 'title').and('contain', 'Use Save or Apply');
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      if (new URL(request.url).searchParams.get('task') === 'draft.preserve') {
        request.alias = 'preserveDraft';
        request.continue((reply) => reply.setDelay(500));
      }
    });
    cy.get('[data-draft-state="clean"] .icon-check').should('exist');
    cy.scrollTo('bottom');
    cy.get('#toolbar [data-draft-ui]').should('be.visible').then((indicator) => {
      cy.window().then((win) => {
        const bounds = indicator[0].getBoundingClientRect();
        expect(bounds.top).to.be.at.least(0);
        expect(bounds.bottom).to.be.at.most(win.innerHeight);
      });
    });
    cy.scrollTo('top');
    cy.get('#jform_alias').type('phase-two-status-fixture');
    cy.get('[data-draft-state="preserving"]').should('be.visible').and('contain.text', 'Saving...');
    cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="preserved"]').should('be.visible').and('contain.text', 'Saved')
      .find('.icon-check').should('exist');
    cy.get('[data-draft-status] time').should('not.exist');
  });

  it('keeps toolbar Retry available across repeated detection failures', () => {
    let detections = 0;
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      if (new URL(request.url).searchParams.get('task') === 'draft.detect') {
        request.alias = 'detectDraft';
        detections += 1;
        if (detections <= 2) request.reply({ statusCode: 503, body: { success: false, error: { code: 'service_unavailable' } } });
      }
    });
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.wait('@detectDraft').its('response.statusCode').should('equal', 503);
    cy.get('#toolbar').find('[data-draft-action="retry"]').should('be.visible').click();
    cy.wait('@detectDraft').its('response.statusCode').should('equal', 503);
    cy.get('#toolbar').find('[data-draft-action="retry"]').should('be.visible').click();
    cy.wait('@detectDraft').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="clean"]').should('be.visible').and('contain.text', 'Autosave ready');
  });

  it('starts fresh New Article without offering a different unfinished creation', () => {
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.wait('@detectDraft');
    cy.get('[data-draft-recovery]').should('not.be.visible');
    cy.get('#jform_alias').clear().type('phase-two-independent-creation');
    cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
    cy.clickToolbarButton('Cancel');
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.wait('@detectDraft').its('response.body.data.candidates').should('have.length', 0);
    cy.get('#jform_title').should('have.value', '');
    cy.get('#jform_alias').should('have.value', '');
    cy.get('[data-draft-recovery]').should('not.be.visible');
    cy.get('[data-draft-action="show-recovery"]').should('not.exist');
    cy.window().then((win) => {
      expect(win.history.state?.joomlaAutosaveCreation).to.equal(undefined);
      const id = win.Joomla.getOptions('com_content.autosave.article').draftId;
      cy.task('queryDB', `SELECT payload FROM #__autosave_drafts WHERE draft_id = '${id}'`)
        .then((rows) => expect(rows[0].payload).to.equal(null));
    });
  });

  it('offers the same unfinished creation automatically after repeated reloads', () => {
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.wait('@detectDraft');
    cy.get('#jform_alias').clear().type('phase-two-reload-creation');
    cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
    cy.reload();
    rememberInstance();
    cy.wait('@detectDraft').its('response.body.data.candidates').should('have.length', 1);
    cy.get('[data-draft-recovery]').should('be.visible');
    cy.get('[data-draft-action="show-recovery"]').should('not.exist');
    cy.reload();
    rememberInstance();
    cy.wait('@detectDraft').its('response.body.data.candidates').should('have.length', 1);
    cy.get('[data-draft-recovery]').should('be.visible');
    cy.get('[data-draft-action="review"]').click();
    cy.get('[data-draft-comparison]').should('contain.text', 'phase-two-reload-creation');
    cy.get('[data-draft-action="restore"]').should('be.enabled').click();
    cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
    cy.get('#jform_alias').should('have.value', 'phase-two-reload-creation');
  });

  it('protects an incomplete creation without creating a canonical Article', () => {
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.get('#jform_alias').clear().type('phase-two-incomplete-draft');
    cy.wait('@preserveDraft').then(({ request, response }) => {
      expect(response.body.success).to.equal(true);
      expect(request.body.payload.title).to.equal('');
      expect(Object.keys(request.body.payload)).to.include('alias');
      expect(Object.keys(request.body.payload)).to.not.include('access');
    });
    cy.task('queryDB', "SELECT COUNT(*) AS total FROM #__content WHERE alias = 'phase-two-incomplete-draft'")
      .then((rows) => expect(Number(rows[0].total)).to.equal(0));
  });

  it('recovers only selected fields into a fresh native form', () => {
    cy.db_createArticle({ title: 'Phase 2 autosave fixture' }).then((article) => {
      cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${article.id}`);
      rememberInstance();
      cy.get('#jform_title').clear().type('Phase 2 recovered title');
      cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
      cy.reload();
      rememberInstance();
      cy.get('#jform_access').select('Special');
      cy.get('[data-draft-recovery]').should('be.visible').then((recovery) => {
        cy.get('#jform_title').then((title) => {
          expect(recovery[0].getBoundingClientRect().bottom).to.be.at.most(title[0].getBoundingClientRect().top);
        });
      });
      cy.get('#jform_title').should('have.value', 'Phase 2 autosave fixture');
      cy.get('[data-draft-action="review"]').click();
      cy.get('[data-draft-comparison] tr').should('have.length.greaterThan', 0);
      cy.get('#jform_title').should('have.value', 'Phase 2 autosave fixture');
      cy.get('[data-draft-action="restore"]').should('be.enabled').click();
      cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
      cy.get('#jform_title').should('have.value', 'Phase 2 recovered title');
      cy.get('#jform_access option:selected').should('have.text', 'Special');
      cy.clickToolbarButton('Save & Close');
      cy.checkForSystemMessage('Article saved.');
      cy.task('queryDB', `SELECT title FROM #__content WHERE id = ${article.id}`)
        .then((rows) => expect(rows[0].title).to.equal('Phase 2 recovered title'));
      cy.then(() => cy.task('queryDB', `SELECT payload, retired_revision FROM #__autosave_drafts WHERE draft_id IN ('${draftIds.join("','")}')`))
        .then((rows) => {
          expect(rows).to.have.length(2);
          rows.forEach((row) => {
            expect(row.payload).to.equal(null);
            expect(Number(row.retired_revision)).to.be.greaterThan(0);
          });
        });
      cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${article.id}`);
      rememberInstance();
      cy.wait('@detectDraft');
      cy.get('[data-draft-recovery]').should('not.be.visible');
      cy.get('#jform_title').should('have.value', 'Phase 2 recovered title');
      cy.get('[data-draft-state="clean"]').should('be.visible').and('contain.text', 'Autosave ready');
    });
  });

  it('retires the working draft on Apply and offers only later unsaved work on reopening', () => {
    cy.db_createArticle({ title: 'Phase 2 autosave fixture' }).then((article) => {
      cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${article.id}`);
      rememberInstance();
      cy.wait('@detectDraft');
      cy.get('#toolbar [data-draft-action="show-recovery"]').should('not.exist');
      cy.get('#jform_title').clear().type('Phase 2 recovered title');
      cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
      cy.clickToolbarButton('Save');
      cy.checkForSystemMessage('Article saved.');
      rememberInstance();
      cy.wait('@detectDraft');
      cy.get('[data-draft-recovery]').should('not.be.visible');
      cy.get('#jform_title').should('have.value', 'Phase 2 recovered title');
      cy.then(() => cy.task('queryDB', `SELECT payload FROM #__autosave_drafts WHERE draft_id = '${draftIds[0]}'`))
        .then((rows) => expect(rows[0].payload).to.equal(null));
      cy.get('#jform_title').clear().type('Phase 2 autosave fixture');
      cy.wait('@preserveDraft').its('response.body.success').should('equal', true);
      cy.clickToolbarButton('Cancel');
      cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${article.id}`);
      rememberInstance();
      cy.wait('@detectDraft');
      cy.get('#jform_title').should('have.value', 'Phase 2 recovered title');
      cy.get('[data-draft-recovery]').should('be.visible');
      cy.get('[data-draft-action="review"]').click();
      cy.get('[data-draft-comparison]').should('contain.text', 'Phase 2 autosave fixture');
      cy.get('#jform_title').should('have.value', 'Phase 2 recovered title');
    });
  });

  [false, true].forEach((creating) => {
    it(`retires TinyMCE drafts through native Apply and Save & Close for ${creating ? 'new' : 'existing'} Articles`, () => {
      const open = creating
        ? cy.visit('/administrator/index.php?option=com_content&task=article.add')
        : cy.db_createArticle({ title: 'Phase 2 autosave fixture' }).then((article) => {
            cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${article.id}`);
          });
      open.then(() => {
        rememberInstance();
        cy.wait('@detectDraft');
        cy.get('#jform_title').clear().type('Phase 2 autosave fixture');
        cy.get('#jform_articletext_ifr').its('0.contentDocument.body').should('not.be.empty')
          .then((body) => cy.wrap(body).clear().type('Canonical body saved through TinyMCE'));
        cy.get('[data-draft-state="preserved"]').should('be.visible');
        cy.clickToolbarButton('Save');
        cy.checkForSystemMessage('Article saved.');
        rememberInstance();
        cy.wait('@detectDraft').its('response.body.data.candidates').should('have.length', 0);
        cy.get('[data-draft-recovery]').should('not.be.visible');
        cy.get('#jform_title').should('have.value', 'Phase 2 autosave fixture');
        cy.then(() => cy.task('queryDB', `SELECT payload, retired_revision FROM #__autosave_drafts WHERE draft_id = '${draftIds[0]}'`))
          .then((rows) => {
            expect(rows[0].payload).to.equal(null);
            expect(Number(rows[0].retired_revision)).to.be.greaterThan(0);
          });
        cy.get('#jform_articletext_ifr').its('0.contentDocument.body')
          .should('contain.text', 'Canonical body saved through TinyMCE')
          .then((body) => cy.wrap(body).clear().type('Later canonical body saved through TinyMCE'));
        cy.get('[data-draft-state="preserved"]').should('be.visible');
        cy.clickToolbarButton('Save & Close');
        cy.checkForSystemMessage('Article saved.');
        cy.then(() => cy.task('queryDB', `SELECT payload FROM #__autosave_drafts WHERE draft_id = '${draftIds[1]}'`))
          .then((rows) => expect(rows[0].payload).to.equal(null));
        cy.task('queryDB', "SELECT id, introtext FROM #__content WHERE title = 'Phase 2 autosave fixture'")
          .then((rows) => {
            expect(rows).to.have.length(1);
            expect(rows[0].introtext).to.contain('Later canonical body saved through TinyMCE');
            cy.visit(`/administrator/index.php?option=com_content&task=article.edit&id=${rows[0].id}`);
          });
        rememberInstance();
        cy.wait('@detectDraft').its('response.body.data.candidates').should('have.length', 0);
        cy.get('[data-draft-recovery]').should('not.be.visible');
        cy.get('[data-draft-state="clean"]').should('be.visible');
        cy.get('#jform_articletext_ifr').its('0.contentDocument.body')
          .should('contain.text', 'Later canonical body saved through TinyMCE');
      });
    });
  });

  it('keeps ordinary native saving available when draft preservation fails', () => {
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    rememberInstance();
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      if (new URL(request.url).searchParams.get('task') === 'draft.preserve') {
        request.alias = 'unavailableDraft';
        request.reply({ statusCode: 503, body: { success: false, error: { code: 'service_unavailable' } } });
      }
    });
    cy.get('#jform_title').clear().type('Phase 2 autosave fixture');
    cy.wait('@unavailableDraft');
    cy.get('[data-draft-state="offline"]').should('be.visible');
    cy.clickToolbarButton('Save & Close');
    cy.checkForSystemMessage('Article saved.');
    cy.task('queryDB', "SELECT COUNT(*) AS total FROM #__content WHERE title = 'Phase 2 autosave fixture'")
      .then((rows) => expect(Number(rows[0].total)).to.equal(1));
  });
});
