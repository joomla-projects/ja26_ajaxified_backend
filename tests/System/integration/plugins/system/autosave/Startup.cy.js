/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Shared draft startup in a browser document', () => {
  let application;
  let userId;
  let binding;
  let requests;
  let sequence = 0;

  const cloneForm = (id, draftId) => {
    const doc = application.document;
    const original = doc.getElementById('item-form');
    const form = original.cloneNode(false);
    form.id = id;
    // Copy the native controls under test without reconnecting unrelated initialized widgets.
    form.append(
      original.querySelector('[data-draft-feedback]').cloneNode(true),
      original.querySelector('[data-draft-recovery]').cloneNode(true),
      doc.getElementById(binding.fields.title.id).cloneNode(true),
    );
    form.querySelectorAll('[id]').forEach((element) => {
      element.id = `${id}-${element.id}`;
    });
    const ui = doc.getElementById(binding.statusId).cloneNode(true);
    ui.id = `${id}-status`;
    doc.body.append(ui, form);
    return {
      form,
      configuration: {
        ...binding, creating: false, draftId, formId: id, statusId: ui.id,
        fields: { title: { ...binding.fields.title, id: `${id}-${binding.fields.title.id}` } },
      },
    };
  };

  const start = (configurations, suffix = '') => {
    // A scalar reset avoids loadOptions merging the next object into a null destination.
    application.Joomla.loadOptions({ 'com_autosave.forms': false });
    application.Joomla.loadOptions({ 'com_autosave.forms': configurations });
    expect(application.Joomla.getOptions('com_autosave.forms')).to.equal(configurations);
    sequence += 1;
    const url = new URL(`media/com_autosave/js/draft-form-startup${suffix}.js?test=${sequence}`, application.Joomla.getOptions('system.paths').rootFull).href;
    // Native module evaluation uses the real document and Joomla import map.

    return application.Function('url', 'return import(url)')(url);
  };

  beforeEach(() => {
    userId = null;
    binding = null;
    application = null;
    requests = [];
    const username = `autosave-startup-${Date.now()}-${Cypress._.random(10000, 99999)}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 8, params: '{"editor":"none","allowTourAutoStart":0}' })
      .then((id) => { userId = Number(id); });
    cy.doAdministratorLogin(username, 'test', false);
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.detect') {
        request.alias = 'detect';
        requests.push(request.body.draft_id);
        request.reply({ success: true, data: { candidates: [], conflict: false } });
      }
    });
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    cy.get('#item-form').should('be.visible');
    cy.window().should((win) => {
      expect(win.Joomla.getOptions('com_autosave.forms')).to.have.property('item-form');
      expect(win.Joomla.getOptions('com_autosave.forms')['item-form'].available).to.equal(true);
    });
    cy.wait('@detect').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="clean"]').should('be.visible');
    cy.window().then((win) => {
      application = win;
      binding = win.Joomla.getOptions('com_autosave.forms')['item-form'];
      requests.length = 0;
    });
  });

  afterEach(() => {
    if (userId) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${userId}`);
  });

  ['', '.min'].forEach((suffix) => {
    it(`starts two real forms independently with the published ${suffix ? 'minified' : 'unminified'} asset`, () => {
      let first;
      let second;
      cy.then(() => {
        first = cloneForm('first-form', 'b'.repeat(32));
        second = cloneForm('second-form', 'c'.repeat(32));
        return start({ 'first-form': first.configuration, 'second-form': second.configuration }, suffix);
      });
      cy.wait('@detect');
      cy.wait('@detect');
      cy.then(() => {
        expect(requests.sort()).to.deep.equal(['b'.repeat(32), 'c'.repeat(32)]);
        expect(first.form.querySelector('[name="jform[title]"]').form).to.equal(first.form);
        expect(second.form.querySelector('[name="jform[title]"]').form).to.equal(second.form);
        // Repeated DOM readiness does not remount the same startup module.
        application.document.dispatchEvent(new application.Event('DOMContentLoaded'));
        expect(requests).to.have.length(2);
      });
    });

    ['ready', 'offline', 'unavailable'].forEach((outcome) => {
      it(`settles ${outcome} and disposes the published ${suffix || '.js'} startup`, () => {
        let current;
        cy.intercept('POST', '**/administrator/index.php*', (request) => {
          const task = new URL(request.url).searchParams.get('task');
          if (task === 'draft.detect' && request.body.draft_id === 'b'.repeat(32)) {
            request.alias = 'variantDetect';
            if (outcome === 'offline') request.reply({ statusCode: 503, body: { success: false, data: { code: 'service_unavailable' } } });
            else request.reply({ success: true, data: { candidates: [], conflict: false } });
          }
        });
        cy.then(() => {
          current = cloneForm('variant-form', 'b'.repeat(32));
          current.configuration.available = outcome !== 'unavailable';
          current.configuration.errorCode = 'service_unavailable';
          return start({ 'variant-form': current.configuration }, suffix);
        });
        if (outcome !== 'unavailable') cy.wait('@variantDetect');
        cy.get('#variant-form-status').find(`[data-draft-state="${outcome === 'ready' ? 'clean' : outcome}"]`).should('be.visible');
        cy.clock(Date.now(), ['setTimeout', 'clearTimeout', 'Date']);
        cy.window().then((win) => win.dispatchEvent(new win.Event('pagehide')));
        cy.get('#variant-form-status [data-draft-state="unavailable"]').should('be.visible');
        cy.intercept('POST', '**/administrator/index.php*', (request) => {
          if (new URL(request.url).searchParams.get('task') === 'draft.preserve') throw new Error('A departed form must not preserve');
        });
        cy.get('#variant-form-jform_title').type('Local text after departure');
        cy.tick(60000);
        cy.get('#variant-form-jform_title').should('have.value', 'Local text after departure');
      });
    });
  });

  it('skips ambiguous, foreign, missing and mismatched form identities', () => {
    cy.then(() => {
      const first = cloneForm('ambiguous', 'b'.repeat(32));
      const second = cloneForm('ambiguous', 'c'.repeat(32));
      const foreign = application.document.createElement('div');
      foreign.id = 'foreign';
      application.document.body.append(foreign);
      expect(first.form.id).to.equal(second.form.id);
      return start({
        ambiguous: first.configuration,
        foreign: { ...binding, formId: 'foreign' },
        missing: { ...binding, formId: 'missing' },
        'item-form': { ...binding, formId: 'different' },
      });
    });
    cy.then(() => expect(requests).to.have.length(0));
  });

  it('waits for DOM readiness and mounts each form only once', () => {
    cy.then(() => {
      const first = cloneForm('waiting-form', 'b'.repeat(32));
      cy.stub(application.document, 'readyState').get(() => 'loading');
      return start({ 'waiting-form': first.configuration });
    });
    cy.then(() => {
      expect(requests).to.have.length(0);
      application.document.dispatchEvent(new application.Event('DOMContentLoaded'));
    });
    cy.wait('@detect');
    cy.then(() => {
      application.document.dispatchEvent(new application.Event('DOMContentLoaded'));
      expect(requests).to.deep.equal(['b'.repeat(32)]);
    });
  });

  [null, [], {}, 'unsupported'].forEach((configuration) => {
    it(`ignores missing or malformed options: ${JSON.stringify(configuration)}`, () => {
      cy.then(() => start(configuration));
      cy.then(() => expect(requests).to.have.length(0));
    });
  });

  it('continues mounting another form after one optional binding throws', () => {
    cy.then(() => {
      const broken = cloneForm('broken-form', 'b'.repeat(32));
      broken.form.querySelector('[data-draft-feedback]').remove();
      const healthy = cloneForm('healthy-form', 'c'.repeat(32));
      return start({ 'broken-form': broken.configuration, 'healthy-form': healthy.configuration });
    });
    cy.wait('@detect');
    cy.then(() => expect(requests).to.deep.equal(['c'.repeat(32)]));
  });
});
