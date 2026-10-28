/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Installed dynamic draft parameters', () => {
  let userId;
  let moduleId;
  let styleId;

  const openEditor = (component, listView, itemView, id, clientId, formId) => {
    // Enter through Joomla's native list so client state and edit-ID handling stay native.
    cy.visit(`/administrator/index.php?option=${component}&view=${listView}&client_id=${clientId}&filter=`);
    cy.intercept({
      method: 'POST', pathname: '**/administrator/index.php',
      query: { option: component, view: listView },
    }).as('parameterSearch');
    cy.document().then((previousDocument) => {
      cy.searchForItem(`id:${id}`);
      cy.wait('@parameterSearch').its('response.statusCode').should('equal', 200);
      cy.document().should((document) => expect(document, 'filtered list document').to.not.equal(previousDocument));
    });
    cy.get('#filter_search').should('have.value', `id:${id}`);
    cy.get(`a[href*="task=${itemView}.edit"][href*="id=${id}"]`).should('have.length', 1).click();
    cy.get(`#${formId}`).should('be.visible');
    cy.location('search').should((search) => {
      const query = new URLSearchParams(search);
      expect(query.get('option')).to.equal(component);
      expect(query.get('view')).to.equal(itemView);
      expect(query.get('id')).to.equal(String(id));
    });
  };

  const reveal = (selector) => {
    cy.get(selector).then((control) => {
      const panel = control[0].closest('joomla-tab-element');
      // Joomla creates both tab and accordion buttons for each panel.
      if (panel && !panel.hasAttribute('active')) {
        panel.closest('joomla-tab').activateTab(panel);
      }
    });
    cy.get(selector).should('be.visible');
  };

  const binding = (formId, included, excluded) => cy.window().should((win) => {
    expect(win.Joomla.getOptions('com_autosave.forms')).to.have.property(formId);
    const config = win.Joomla.getOptions('com_autosave.forms')[formId];
    expect(config.available).to.equal(true);
    expect(Object.keys(config.fields)).to.include.members(included);
    expect(Object.keys(config.fields)).to.not.include.members(excluded);
  });

  const restore = () => {
    cy.get('[data-draft-recovery]').should('be.visible');
    cy.get('[data-draft-action="review"]').click();
    cy.get('[data-draft-comparison] tr').should('have.length.greaterThan', 0);
    cy.get('[data-draft-action="restore"]').should('be.enabled').click();
    cy.wait('@preserve').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="preserved"]').should('be.visible');
  };

  beforeEach(() => {
    userId = null;
    moduleId = null;
    styleId = null;
    const username = `autosave-parameters-${Date.now()}-${Cypress._.random(10000, 99999)}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 8, params: '{"editor":"none","allowTourAutoStart":0}' })
      .then((id) => { userId = Number(id); });
    // Keep delayed dashboard session writes out of the editor's native edit-ID setup.
    cy.intercept({
      method: 'GET', pathname: '**/administrator/index.php',
      query: { option: 'com_joomlaupdate', task: 'update.healthstatus' },
    }, { body: { active: false, healthy: true } });
    cy.intercept({
      method: 'GET', pathname: '**/administrator/index.php',
      query: { option: 'com_joomlaupdate', task: 'update.ajax' },
    }, { body: [] });
    cy.doAdministratorLogin(username, 'test', false);
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.preserve') request.alias = 'preserve';
      if (task === 'draft.detect') request.alias = 'detect';
    });
  });

  afterEach(() => {
    if (userId) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${userId}`);
    if (moduleId) {
      cy.task('queryDB', `DELETE FROM #__assets WHERE name = 'com_modules.module.${moduleId}'`);
    }
  });

  [false, true].forEach((clearing) => {
    it(`restores a Module parameter ${clearing ? 'cleared deliberately' : 'edited'} and saves through the native form`, () => {
      const value = clearing ? '' : 'recovered-module-class';
      cy.db_createModule({ title: 'Autosave parameter fixture', module: 'mod_custom', params: '{"moduleclass_sfx":"original-class","cachemode":"static"}' }).then((id) => {
        moduleId = Number(id);
        openEditor('com_modules', 'modules', 'module', moduleId, 0, 'module-form');
      });
      cy.wait('@detect');
      binding('module-form', ['params.moduleclass_sfx', 'params.prepare_content'], ['params.cachemode', 'access']);
      reveal('#jform_params_moduleclass_sfx');
      cy.get('#jform_params_moduleclass_sfx').should('have.value', 'original-class').clear();
      if (value) cy.get('#jform_params_moduleclass_sfx').type(value);
      cy.wait('@preserve').then(({ request, response }) => {
        expect(response.body.success).to.equal(true);
        expect(request.body.payload['params.moduleclass_sfx']).to.equal(value);
        expect(request.body.payload).to.not.have.property('params.cachemode');
      });
      cy.get('[data-draft-state="preserved"]').should('be.visible');
      cy.reload();
      cy.wait('@detect');
      cy.get('#jform_params_moduleclass_sfx').should('have.value', 'original-class');
      restore();
      cy.get('#jform_params_moduleclass_sfx').should('have.value', value);
      cy.clickToolbarButton('Save & Close');
      cy.checkForSystemMessage('Module saved');
      cy.then(() => cy.task('queryDB', `SELECT params FROM #__modules WHERE id = ${moduleId}`))
        .then((rows) => {
          const params = JSON.parse(rows[0].params);
          expect(params.moduleclass_sfx).to.equal(value);
          expect(params.cachemode).to.equal('static');
        });
    });
  });

  [false, true].forEach((clearing) => {
    it(`restores Cassiopeia text parameters ${clearing ? 'including deliberate clearing' : 'with existing saved values'} before native Save`, () => {
      const value = clearing ? '' : 'Recovered site title';
      cy.task('queryDB', 'INSERT INTO #__template_styles (template, client_id, home, title, params, inheritable, parent) VALUES (\'cassiopeia\', 0, \'0\', \'Autosave style fixture\', \'{"brand":1,"siteTitle":"Original site title","siteDescription":"Original tagline","useFontScheme":"system"}\', 0, \'\')').then((info) => {
        styleId = Number(info.insertId);
        openEditor('com_templates', 'styles', 'style', styleId, 0, 'style-form');
      });
      cy.wait('@detect');
      binding('style-form', ['params.siteTitle', 'params.siteDescription'], ['params.useFontScheme', 'assigned']);
      reveal('#jform_params_siteTitle');
      cy.get('#jform_params_siteTitle').should('have.value', 'Original site title').clear();
      if (value) cy.get('#jform_params_siteTitle').type(value);
      cy.wait('@preserve').then(({ request, response }) => {
        expect(response.body.success).to.equal(true);
        expect(request.body.payload['params.siteTitle']).to.equal(value);
        expect(request.body.payload).to.not.have.property('params.useFontScheme');
      });
      cy.get('[data-draft-state="preserved"]').should('be.visible');
      cy.reload();
      cy.wait('@detect');
      cy.get('#jform_params_siteTitle').should('have.value', 'Original site title');
      restore();
      cy.get('#jform_params_siteTitle').should('have.value', value);
      cy.get('#jform_params_siteDescription').should('have.value', 'Original tagline');
      cy.clickToolbarButton('Save & Close');
      cy.checkForSystemMessage('Style saved');
      cy.then(() => cy.task('queryDB', `SELECT params, home FROM #__template_styles WHERE id = ${styleId}`))
        .then((rows) => {
          const params = JSON.parse(rows[0].params);
          expect(params.siteTitle).to.equal(value);
          expect(params.siteDescription).to.equal('Original tagline');
          expect(params.useFontScheme).to.equal('system');
          expect(rows[0].home).to.equal('0');
        });
    });
  });

  it('restores Atum radio and select widgets on a non-default administrator style', () => {
    cy.task('queryDB', 'INSERT INTO #__template_styles (template, client_id, home, title, params, inheritable, parent) VALUES (\'atum\', 1, \'0\', \'Autosave style fixture\', \'{"monochrome":0,"colorScheme":"light"}\', 0, \'\')').then((info) => {
      styleId = Number(info.insertId);
      openEditor('com_templates', 'styles', 'style', styleId, 1, 'style-form');
    });
    cy.wait('@detect');
    binding('style-form', ['params.monochrome', 'params.colorScheme'], ['assigned', 'client_id']);
    reveal('#jform_params_colorScheme');
    cy.get('#jform_params_colorScheme').should('have.value', 'light').select('dark');
    cy.get('label[for="jform_params_monochrome1"]').click();
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.reload();
    cy.wait('@detect');
    cy.get('#jform_params_colorScheme').should('have.value', 'light');
    restore();
    cy.get('#jform_params_colorScheme').should('have.value', 'dark');
    cy.get('#jform_params_monochrome input[value="1"]').should('be.checked');
    cy.clickToolbarButton('Save & Close');
    cy.checkForSystemMessage('Style saved');
    cy.then(() => cy.task('queryDB', `SELECT params, home FROM #__template_styles WHERE id = ${styleId}`))
      .then((rows) => {
        const params = JSON.parse(rows[0].params);
        expect(String(params.monochrome)).to.equal('1');
        expect(params.colorScheme).to.equal('dark');
        expect(rows[0].home).to.equal('0');
      });
  });
});
