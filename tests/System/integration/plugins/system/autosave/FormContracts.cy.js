/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Draft binding on the real native Article form', () => {
  let userId;
  let application;
  let writes;
  let disposers;
  let holdPreserve;
  let releasePreserve;

  const remount = async (fields, prepare = () => {}, overrides = {}) => {
    application.dispatchEvent(new application.Event('pagehide'));
    const configuration = application.Joomla.getOptions('com_autosave.forms')['item-form'];
    const form = application.document.getElementById('item-form');
    prepare(form);

    const { default: mount } = await application.Function('url', 'return import(url)')(new URL('media/com_autosave/js/draft-form.js', application.Joomla.getOptions('system.paths').rootFull).href);
    disposers.push(mount(form, { ...configuration, fields, ...overrides }));
  };

  beforeEach(() => {
    userId = null;
    writes = [];
    disposers = [];
    holdPreserve = false;
    releasePreserve = null;
    const username = `autosave-form-${Date.now()}-${Cypress._.random(10000, 99999)}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 8, params: '{"editor":"none","allowTourAutoStart":0}' })
      .then((id) => { userId = Number(id); });
    cy.doAdministratorLogin(username, 'test', false);
    cy.intercept('POST', '**/administrator/index.php*', (request) => {
      const task = new URL(request.url).searchParams.get('task');
      if (task === 'draft.detect') {
        request.alias = 'detect';
        request.reply({ success: true, data: { candidates: [], conflict: false } });
      }
      if (task === 'draft.preserve') {
        request.alias = 'preserve';
        writes.push(request.body);
        const reply = () => request.reply({ success: true, data: { client_revision: request.body.client_revision, updated_at: '2026-10-16T00:00:00Z', conflict: false } });
        if (holdPreserve) {
          return new Promise((resolve) => {
            releasePreserve = () => {
              releasePreserve = null;
              reply();
              resolve();
            };
          });
        }
        reply();
      }
      return undefined;
    });
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    cy.get('#item-form').should('be.visible');
    cy.window().should((win) => {
      expect(win.Joomla.getOptions('com_autosave.forms')).to.have.property('item-form');
      expect(win.Joomla.getOptions('com_autosave.forms')['item-form'].available).to.equal(true);
      expect(win.Joomla.editors.instances).to.have.property('jform_articletext');
    });
    cy.wait('@detect').its('response.body.success').should('equal', true);
    cy.get('[data-draft-state="clean"]').should('be.visible');
    cy.window().then((win) => {
      application = win;
    });
    cy.clock(Date.now(), ['setTimeout', 'clearTimeout', 'Date']);
  });

  afterEach(() => {
    if (releasePreserve) releasePreserve();
    disposers.forEach((dispose) => dispose());
    if (userId) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${userId}`);
  });

  describe('Owner-only controls when allocation is full', () => {
    let entries;
    let confirmation;
    let historyBefore;

    beforeEach(() => {
      entries = [
        {
          draft_id: 'a'.repeat(32), context: 'com_content.article', target_id: '42',
          created_at: '2026-10-16T00:00:00Z', updated_at: '2026-10-16T00:01:00Z', expires_at: '2026-10-23T00:01:00Z',
          has_preserved_values: true, has_save_marker: false,
        },
        {
          draft_id: 'b'.repeat(32), context: 'com_modules.module', target_id: null,
          created_at: '2026-10-16T00:00:00Z', updated_at: '2026-10-16T00:02:00Z', expires_at: '2026-10-17T00:02:00Z',
          has_preserved_values: false, has_save_marker: true,
        },
      ];
      cy.then(async () => {
        const { default: dialog } = await application.Function('return import("joomla.dialog")')();
        confirmation = cy.stub(dialog, 'confirm').resolves(true);
        historyBefore = JSON.stringify(application.history.state);
        await remount({}, () => {}, { available: false, errorCode: 'draft_limit_reached', quota: entries });
      });
      cy.get('[data-draft-quota]').should('be.visible');
      cy.get('#jform_title').type('Local work at full quota');
    });

    it('discards just the confirmed identity, keeps local values and does not restart protection', () => {
      cy.intercept('POST', '**/administrator/index.php*task=draft.discard*', {
        body: { success: true, data: { discarded: true } },
      }).as('quotaDiscard');
      cy.get('[data-draft-quota-entries] option').should('have.length', 2);
      cy.get('[data-draft-quota-values]').should('be.visible');
      cy.get('[data-draft-quota-entries]').select(entries[1].draft_id);
      cy.get('[data-draft-quota-no-values]').should('be.visible');
      cy.get('[data-draft-quota-saved]').should('be.visible');
      cy.get('[data-draft-quota-entries]').select(entries[0].draft_id);
      cy.get('[data-draft-quota-discard]').click();
      cy.wait('@quotaDiscard').then(({ request }) => {
        expect(request.body).to.deep.equal({ draft_id: entries[0].draft_id });
        expect(request.headers['x-csrf-token']).to.equal(application.Joomla.getOptions('csrf.token'));
        expect(confirmation).to.have.been.calledOnce;
        expect(confirmation.firstCall.args[0]).to.include('since this list was shown');
      });
      cy.get('[data-draft-quota-entries] option').should('have.length', 1);
      cy.get('[data-draft-quota-success]').should('be.visible');
      cy.get('[data-draft-state="unavailable"]').should('be.visible');
      cy.tick(60000);
      cy.get('#jform_title').should('have.value', 'Local work at full quota');
      cy.get('[data-draft-metadata]').should('not.exist');
      cy.then(() => {
        expect(writes).to.have.length(0);
        expect(JSON.stringify(application.history.state)).to.equal(historyBefore);
        const form = application.document.getElementById('item-form');
        form.elements.namedItem('task').value = 'article.save';
        const event = new application.Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);
        expect(event.defaultPrevented).to.equal(false);
      });
    });

    it('does nothing when the owner cancels confirmation', () => {
      let requests = 0;
      cy.intercept('POST', '**/administrator/index.php*task=draft.discard*', (request) => {
        requests += 1;
        request.reply({ success: true, data: { discarded: true } });
      });
      cy.then(() => {
        confirmation.resolves(false);
      });
      cy.get('[data-draft-quota-discard]').click();
      cy.get('[data-draft-quota-discard]').should('not.be.disabled');
      cy.get('[data-draft-quota-entries] option').should('have.length', 2);
      cy.get('#jform_title').should('have.value', 'Local work at full quota');
      cy.then(() => expect(requests).to.equal(0));
    });

    it('reports a stale identity without claiming that a deletion freed space', () => {
      cy.intercept('POST', '**/administrator/index.php*task=draft.discard*', {
        statusCode: 404, body: { success: false, data: { code: 'draft_not_found' } },
      }).as('quotaDiscard');
      cy.get('[data-draft-quota-discard]').click();
      cy.wait('@quotaDiscard');
      cy.get('[data-draft-quota-stale]').should('be.visible');
      cy.get('[data-draft-quota-success]').should('not.be.visible');
      cy.get('[data-draft-quota-entries] option').should('have.length', 1);
      cy.get('#jform_title').should('have.value', 'Local work at full quota');
    });

    [
      ['invalid_csrf_token', 403, 'authentication_required'],
      ['authentication_required', 401, 'authentication_required'],
      ['transport_failure', 503, 'transport_failure'],
    ].forEach(([code, statusCode, feedback]) => {
      it(`keeps selection and local editing after ${code}`, () => {
        cy.intercept('POST', '**/administrator/index.php*task=draft.discard*', {
          statusCode, body: { success: false, data: { code } },
        }).as('quotaDiscard');
        cy.get('[data-draft-quota-discard]').click();
        cy.wait('@quotaDiscard');
        cy.get(`[data-draft-error~="${feedback}"]`).should('be.visible');
        cy.get('[data-draft-quota-entries]').should('have.value', entries[0].draft_id);
        cy.get('[data-draft-quota-entries] option').should('have.length', 2);
        cy.get('[data-draft-quota-success]').should('not.be.visible');
        cy.get('#jform_title').type(' still editable').should('have.value', 'Local work at full quota still editable');
      });
    });

    [undefined, []].forEach((quota) => {
      it(`keeps native editing available with ${quota ? 'empty' : 'missing'} metadata`, () => {
        cy.then(() => remount({}, () => {}, { available: false, errorCode: 'draft_limit_reached', quota }));
        cy.get('[data-draft-quota-unavailable]').should('be.visible');
        cy.get('[data-draft-quota-entries]').should('be.disabled');
        cy.get('[data-draft-quota-entries] option').should('have.length', 0);
        cy.get('[data-draft-quota-discard]').should('be.disabled');
        cy.get('#jform_title').type(' still editable').should('have.value', 'Local work at full quota still editable');
        cy.tick(60000);
        cy.then(() => expect(writes).to.have.length(0));
      });
    });

    it('aborts a departing form and ignores a late successful discard acknowledgement', () => {
      let signal;
      let release;
      let parsed = false;
      cy.then(() => {
        cy.stub(application, 'fetch').callsFake((url, options) => {
          signal = options.signal;
          return new Promise((resolve) => {
            release = () => resolve({
              ok: true,
              json: async () => {
                parsed = true;
                return { success: true, data: { discarded: true } };
              },
            });
          });
        });
      });
      cy.get('[data-draft-quota-discard]').click();
      cy.wrap(null).should(() => expect(release).to.be.a('function'));
      cy.then(() => {
        application.dispatchEvent(new application.Event('pagehide'));
        expect(signal.aborted).to.equal(true);
        release();
      });
      cy.wrap(null).should(() => expect(parsed).to.equal(true));
      cy.get('[data-draft-quota-discard]').should('be.disabled');
      cy.get('[data-draft-quota-entries] option').should('have.length', 2);
      cy.get('[data-draft-quota-success]').should('not.be.visible');
      cy.get('#jform_title').should('have.value', 'Local work at full quota');
    });
  });

  ['untouched', 'early typing', 'early undo'].forEach((mode) => {
    it(`keeps the original textarea baseline during late editor availability: ${mode}`, () => {
      let api;
      let textarea;
      let wrapper;
      cy.window().then(async (win) => {
        win.dispatchEvent(new win.Event('pagehide'));

        api = (await win.Function('return import("editor-api")')()).JoomlaEditor;
        textarea = win.document.getElementById('jform_articletext');
        wrapper = textarea.closest('joomla-editor-none');
        expect(wrapper).to.not.equal(null);
        expect(api.get(textarea.id).getElement()).to.equal(textarea);
        // Disconnect the native provider while keeping the original textarea in the form.
        wrapper.before(textarea);
        wrapper.remove();
        expect(api.get(textarea.id)).to.equal(false);
        expect(textarea.form).to.equal(win.document.getElementById('item-form'));
        // Late availability starts a fresh native provider around the same textarea.
        wrapper = win.document.createElement('joomla-editor-none');

        const { default: mount } = await win.Function('url', 'return import(url)')(new URL('media/com_autosave/js/draft-form.js', win.Joomla.getOptions('system.paths').rootFull).href);
        const configuration = win.Joomla.getOptions('com_autosave.forms')['item-form'];
        disposers.push(mount(win.document.getElementById('item-form'), {
          ...configuration, fields: { articletext: configuration.fields.articletext },
        }));
      });
      cy.wait('@detect');
      if (mode !== 'untouched') cy.get('#jform_articletext').type('Typed before registration');
      if (mode === 'early undo') cy.get('#jform_articletext').clear();
      cy.then(() => {
        const parent = textarea.parentNode;
        const next = textarea.nextSibling;
        wrapper.append(textarea);
        parent.insertBefore(wrapper, next);
      });
      cy.wrap(null).should(() => {
        const current = api.get(textarea.id);
        expect(current).to.not.equal(false);
        expect(current.getElement()).to.equal(textarea);
      });
      cy.tick(650);
      if (mode === 'early typing') {
        cy.wait('@preserve').its('request.body.payload.articletext').should('equal', 'Typed before registration');
        cy.get('[data-draft-state="preserved"]').should('be.visible');
      } else {
        cy.tick(60000);
        cy.then(() => expect(writes).to.have.length(0));
      }
    });
  });

  it('resumes after a cancelled submit while an earlier preserve is outstanding', () => {
    cy.then(() => {
      holdPreserve = true;
    });
    cy.get('#jform_title').type('Earlier draft');
    cy.tick(650);
    cy.wrap(null).should(() => expect(releasePreserve).to.be.a('function'));
    cy.then(() => {
      const form = application.document.getElementById('item-form');
      form.elements.namedItem('task').value = 'article.save';
      form.addEventListener('submit', (event) => event.preventDefault(), { once: true });
      form.dispatchEvent(new application.Event('submit', { bubbles: true, cancelable: true }));
    });
    cy.tick(0);
    cy.get('[data-draft-metadata]').should('not.exist');
    cy.get('#jform_title').clear();
    cy.get('#jform_title').type('Later local edit');
    cy.window().should((win) => {
      expect(win.history.state.joomlaAutosaveCreation.acknowledgedId).to.equal(null);
    });
    cy.then(() => {
      holdPreserve = false;
      releasePreserve();
    });
    cy.wait('@preserve').its('response.body.success').should('equal', true);
    // Dirty is already visible; the creation reference changes only after the held write is accepted.
    cy.window().should((win) => {
      const configuration = win.Joomla.getOptions('com_autosave.forms')['item-form'];
      expect(win.history.state.joomlaAutosaveCreation.acknowledgedId).to.equal(configuration.draftId);
    });
    cy.tick(650);
    cy.wait('@preserve').then(({ request }) => {
      expect(request.body.payload.title).to.equal('Later local edit');
      expect(request.body.client_revision).to.be.greaterThan(writes[0].client_revision);
    });
    cy.get('[data-draft-state="preserved"]').should('be.visible');
  });

  it('continues editor rebinding when a provider-returned disposer throws', () => {
    let api;
    let previous;
    let replacement;
    let cleanup;
    let reported;
    const failure = new Error('Editor disposer failed');
    cy.then(async () => {
      api = (await application.Function('return import("editor-api")')()).JoomlaEditor;
      reported = cy.stub(application, 'reportError');
      const configuration = application.Joomla.getOptions('com_autosave.forms')['item-form'];
      await remount({ articletext: configuration.fields.articletext }, () => {
        previous = api.get('jform_articletext');
        const subscribe = previous.subscribeChange;
        cy.stub(previous, 'subscribeChange').callsFake((callback) => {
          const off = subscribe.call(previous, callback);
          cleanup = cy.spy(() => {
            off();
            throw failure;
          });
          return cleanup;
        });
      });
    });
    cy.wait('@detect');
    cy.then(() => {
      replacement = new previous.constructor(previous.getRawInstance(), previous.getType(), previous.getId());
      disposers.push(() => {
        api.unregister(replacement);
        api.register(previous);
      });
      expect(api.register(replacement)).to.equal(api);
      api.unregister(previous);
      expect(api.get(previous.getId())).to.equal(replacement);
      expect(cleanup).to.have.been.calledOnce;
      expect(reported).to.have.been.calledOnce;
      expect(reported.firstCall.args[0]).to.equal(failure);
    });
    cy.get('#jform_articletext').type('Work after failed disposal');
    cy.tick(650);
    cy.wait('@preserve').its('request.body.payload.articletext').should('equal', 'Work after failed disposal');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.then(() => previous.notifyChange());
    cy.tick(650);
    cy.then(() => {
      expect(writes).to.have.length(1);
      expect(cleanup).to.have.been.calledOnce;
      expect(reported).to.have.been.calledOnce;
    });
  });

  it('resumes from a real same-ID editor replacement and ignores the removed decorator', () => {
    let previous;
    let replacement;
    cy.then(() => {
      previous = application.Joomla.editors.instances.jform_articletext;
      const textarea = previous.getElement();
      replacement = textarea.cloneNode(true);
      replacement.value = 'Replacement native editor';
      textarea.parentElement.replaceChildren(replacement);
    });
    cy.wrap(null).should(() => {
      expect(application.Joomla.editors.instances.jform_articletext).to.not.equal(previous);
      expect(application.Joomla.editors.instances.jform_articletext.getElement()).to.equal(replacement);
    });
    cy.tick(650);
    cy.wait('@preserve').its('request.body.payload.articletext').should('equal', 'Replacement native editor');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.then(() => previous.notifyChange());
    cy.tick(650);
    cy.then(() => expect(writes).to.have.length(1));
    cy.get('#jform_articletext').type(' with later work');
    cy.tick(650);
    cy.wait('@preserve').its('request.body.payload.articletext').should('equal', 'Replacement native editor with later work');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
  });

  ['name', 'foreign form', 'kind', 'duplicate ID', 'registry ownership', 'ownership error'].forEach((mutation) => {
    it(`rejects a real replacement with invalid editor ownership: ${mutation}`, () => {
      let previous;
      cy.then(() => {
        previous = application.Joomla.editors.instances.jform_articletext;
        const original = previous.getElement();
        const replacement = original.cloneNode(true);
        replacement.value = 'Untrusted replacement';
        if (mutation === 'name') replacement.name = 'jform[other]';
        if (mutation === 'foreign form') replacement.setAttribute('form', 'missing-form');
        if (mutation === 'kind') {
          const input = application.document.createElement('input');
          input.id = original.id;
          input.name = original.name;
          original.replaceWith(input);
        } else original.replaceWith(replacement);
        if (mutation === 'duplicate ID') replacement.after(replacement.cloneNode(true));
      });
      cy.window().then(async (win) => {
        const { JoomlaEditor } = await win.Function('return import("editor-api")')();
        if (mutation === 'registry ownership') {
          // The registry points at a genuine provider for a different textarea.
          const foreign = win.document.createElement('joomla-editor-none');
          const textarea = win.document.createElement('textarea');
          textarea.id = 'foreign-editor';
          foreign.append(textarea);
          win.document.body.append(foreign);
          const provider = JoomlaEditor.get('foreign-editor');
          JoomlaEditor.register(new provider.constructor(provider.getRawInstance(), 'none', 'jform_articletext'));
        }
        if (mutation === 'ownership error') {
          const current = JoomlaEditor.get('jform_articletext');
          JoomlaEditor.unregister(current);
          cy.stub(current, 'getElement').throws(new Error('Provider cannot identify its textarea'));
          JoomlaEditor.register(current);
        }
      });
      cy.get('#jform_alias').type('Validate the whole selected form');
      cy.tick(650);
      cy.get('[data-draft-state="unavailable"]').should('be.visible');
      cy.then(() => {
        previous.notifyChange();
        expect(writes).to.have.length(0);
      });
    });
  });

  ['multiple', 'checkboxes', 'textarea'].forEach((control) => {
    it(`binds a real ${control} control and skips unchanged native values`, () => {
      cy.then(() => {
        const descriptor = application.Joomla.getOptions('com_autosave.forms')['item-form'].fields.title;
        return remount({ title: { ...descriptor, control } }, (form) => {
          const original = form.querySelector('#jform_title');
          let element;
          if (control === 'multiple') {
            element = application.document.createElement('select');
            element.multiple = true;
            element.innerHTML = '<option value="a" selected>A</option><option value="b">B</option>';
          } else if (control === 'checkboxes') {
            element = application.document.createElement('fieldset');
            element.innerHTML = '<input type="checkbox" name="jform[title]" value="a" checked><input type="checkbox" name="jform[title]" value="b">';
          } else element = application.document.createElement('textarea');
          element.id = original.id;
          element.name = original.name;
          original.replaceWith(element);
        });
      });
      cy.wait('@detect');
      cy.get('#jform_title').trigger('change');
      cy.tick(650);
      cy.then(() => expect(writes).to.have.length(0));
      if (control === 'multiple') cy.get('#jform_title').select('b');
      if (control === 'checkboxes') {
        cy.get('#jform_title [value="a"]').uncheck();
        cy.get('#jform_title [value="b"]').check();
      }
      if (control === 'textarea') cy.get('#jform_title').type('Selected native textarea');
      cy.tick(650);
      cy.wait('@preserve').its('request.body.payload.title').should('deep.equal', control === 'textarea' ? 'Selected native textarea' : ['b']);
    });
  });

  it('rejects an input already mismatched when the binder first mounts', () => {
    cy.then(() => {
      const descriptor = application.Joomla.getOptions('com_autosave.forms')['item-form'].fields.title;
      return remount({ title: descriptor }, (form) => {
        form.querySelector('#jform_title').name = 'jform[other]';
      });
    });
    cy.wait('@detect');
    cy.get('#jform_title').type('Local values stay intact');
    cy.tick(60000);
    cy.get('[data-draft-state="unavailable"]').should('be.visible');
    cy.then(() => expect(writes).to.have.length(0));
  });

  it('meets the dirty deadline during continuous real editor input and leaves idle forms alone', () => {
    cy.tick(60000);
    cy.then(() => expect(writes).to.have.length(0));
    for (let index = 0; index < 300; index += 1) {
      cy.get('#jform_articletext').then((input) => {
        input[0].value = `Edit ${index}`;
        input[0].dispatchEvent(new application.Event('input', { bubbles: true }));
      });
      cy.tick(100);
    }
    cy.wait('@preserve').its('request.body.payload.articletext').should('equal', 'Edit 299');
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.tick(90000);
    cy.then(() => expect(writes).to.have.length(1));
  });

  it('does not write unchanged or excluded controls and preserves deliberate clearing', () => {
    cy.get('#jform_title').trigger('input').trigger('change');
    cy.get('#jform_access').select('Special');
    cy.tick(60000);
    cy.then(() => expect(writes).to.have.length(0));
    cy.get('#jform_title').type('Incomplete selected work');
    cy.tick(650);
    cy.wait('@preserve').then(({ request }) => {
      expect(request.body.payload.title).to.equal('Incomplete selected work');
      expect(request.body.payload).to.not.have.property('access');
    });
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.get('#jform_title').clear();
    cy.tick(650);
    cy.wait('@preserve').then(({ request }) => {
      expect(request.body.payload.title).to.equal('');
      expect(request.body.client_revision).to.be.greaterThan(writes[0].client_revision);
    });
    cy.get('[data-draft-state="preserved"]').should('be.visible');
    cy.get('#jform_title').trigger('input').trigger('change');
    cy.tick(60000);
    cy.then(() => expect(writes).to.have.length(2));
  });

  ['before', 'after', 'metadata failure'].forEach((stage) => {
    it(`keeps local editing available when submission is cancelled: ${stage}`, () => {
      cy.then(() => {
        const form = application.document.getElementById('item-form');
        form.elements.namedItem('task').value = 'article.save';
        application.document.getElementById('jform_title').value = 'Submitted values';
        if (stage === 'before') form.ownerDocument.addEventListener('submit', (event) => event.preventDefault(), { capture: true, once: true });
        if (stage === 'after') form.addEventListener('submit', (event) => event.preventDefault(), { once: true });
        if (stage === 'metadata failure') {
          const append = form.appendChild;
          cy.stub(form, 'appendChild').callsFake(function appendMetadata(child) {
            if (child.hasAttribute('data-draft-metadata')) throw new Error('Extension rejected optional metadata');
            return append.call(this, child);
          });
        }
        const event = new application.Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);
        if (stage === 'metadata failure') expect(event.defaultPrevented).to.equal(false);
        else expect(event.defaultPrevented).to.equal(true);
      });
      cy.tick(0);
      cy.get('[data-draft-metadata]').should('not.exist');
      cy.get('#jform_title').clear();
      cy.get('#jform_title').type('Work after cancelled submit');
      cy.tick(650);
      cy.wait('@preserve');
      cy.get('[data-draft-state="preserved"]').should('be.visible');
      cy.wrap(writes).should((records) => expect(records.at(-1).payload.title).to.equal('Work after cancelled submit'));
    });
  });

  ['name', 'foreign form', 'wrong kind', 'hidden type', 'duplicate ID', 'replacement node'].forEach((mutation) => {
    it(`stops protection after a selected control changes identity: ${mutation}`, () => {
      cy.get('#jform_title').then((input) => {
        const element = input[0];
        if (mutation === 'name') element.name = 'jform[foreign]';
        if (mutation === 'foreign form') {
          const foreign = application.document.createElement('form');
          foreign.id = 'foreign-form';
          application.document.body.append(foreign);
          element.setAttribute('form', foreign.id);
        }
        if (mutation === 'hidden type') element.type = 'hidden';
        if (mutation === 'wrong kind') {
          const select = application.document.createElement('select');
          select.id = element.id;
          select.name = element.name;
          element.replaceWith(select);
        }
        if (mutation === 'duplicate ID') element.after(element.cloneNode(true));
        if (mutation === 'replacement node') element.replaceWith(element.cloneNode(true));
        // A different selected control forces full capture and identity validation as well.
      });
      cy.get('#jform_alias').type('forces-current-control-validation');
      cy.tick(650);
      cy.get('[data-draft-state="unavailable"]').should('be.visible');
      cy.then(() => expect(writes).to.have.length(0));
      cy.get('#toolbar').should('be.visible');
    });
  });

  it('treats a detached form as unavailable before capturing or sending values', () => {
    cy.get('#jform_title').type('Pending local values');
    cy.get('#item-form').then((form) => form[0].remove());
    cy.tick(650);
    cy.get('[data-draft-state="unavailable"]').should('be.visible');
    cy.then(() => expect(writes).to.have.length(0));
  });

  it('releases other fields and form listeners when one editor disposer fails', () => {
    let firstCleanup;
    let secondCleanup;
    let lifecycleCleanup;
    let removeListener;
    let reported;
    const failure = new Error('Editor disposer failed');
    cy.then(async () => {
      const { JoomlaEditor: api } = await application.Function('return import("editor-api")')();
      const configuration = application.Joomla.getOptions('com_autosave.forms')['item-form'];
      reported = cy.stub(application, 'reportError');
      await remount({
        articletext: configuration.fields.articletext, title: { ...configuration.fields.title, control: 'editor' },
      }, (form) => {
        const original = form.querySelector('#jform_title');
        const wrapper = application.document.createElement('joomla-editor-none');
        const textarea = application.document.createElement('textarea');
        textarea.id = original.id;
        textarea.name = original.name;
        textarea.value = original.value;
        wrapper.append(textarea);
        original.replaceWith(wrapper);
        const first = api.get('jform_articletext');
        const second = api.get('jform_title');
        expect(second.getElement()).to.equal(textarea);
        [first, second].forEach((provider, index) => {
          const subscribe = provider.subscribeChange;
          cy.stub(provider, 'subscribeChange').callsFake((callback) => {
            const off = subscribe.call(provider, callback);
            const cleanup = cy.spy(() => {
              off();
              if (index === 0) throw failure;
            });
            if (index === 0) firstCleanup = cleanup;
            else secondCleanup = cleanup;
            return cleanup;
          });
        });
        const subscribeLifecycle = api.subscribeLifecycle;
        cy.stub(api, 'subscribeLifecycle').callsFake((callback) => {
          const off = subscribeLifecycle.call(api, callback);
          lifecycleCleanup = cy.spy(() => off());
          return lifecycleCleanup;
        });
        removeListener = cy.spy(form, 'removeEventListener');
      });
    });
    cy.wait('@detect');
    cy.get('#jform_title').type('Pending local work');
    cy.then(() => {
      const dispose = disposers[0];
      expect(() => dispose()).to.not.throw();
      dispose();
      expect(firstCleanup).to.have.been.calledOnce;
      expect(secondCleanup).to.have.been.calledOnce;
      expect(lifecycleCleanup).to.have.been.calledOnce;
      expect(removeListener).to.have.been.calledWith('input');
      expect(removeListener).to.have.been.calledWith('change');
      expect(removeListener).to.have.been.calledWith('submit');
      expect(reported).to.have.been.calledOnce;
      expect(reported.firstCall.args[0]).to.equal(failure);
    });
    cy.tick(60000);
    cy.get('#jform_title').type(' after disposal').should('have.value', 'Pending local work after disposal');
    cy.tick(60000);
    cy.get('[data-draft-state="unavailable"]').should('be.visible');
    cy.get('[data-draft-metadata]').should('not.exist');
    cy.then(() => expect(writes).to.have.length(0));
  });

  it('disposes event listeners and pending work when the page departs', () => {
    cy.get('#jform_title').type('Pending local values');
    cy.window().then((win) => win.dispatchEvent(new win.Event('pagehide')));
    cy.tick(60000);
    cy.get('#jform_title').type(' after departure');
    cy.tick(60000);
    cy.then(() => expect(writes).to.have.length(0));
  });

  it('fails optional protection for a third-party editor without observation or owned-element support', () => {
    cy.window().then(async (win) => {
      const { JoomlaEditor, JoomlaEditorDecorator } = await win.Function('return import("editor-api")')();
      const textarea = win.document.getElementById('jform_articletext');
      // A minimal third-party provider deliberately implements only the pre-existing value API.
      class LegacyEditor extends JoomlaEditorDecorator {
        getValue() { return textarea.value; }

        setValue(value) {
          textarea.value = value;
          return this;
        }
      }
      const legacy = new LegacyEditor(textarea, 'third-party', textarea.id);
      expect(legacy.supportsChangeObservation()).to.equal(false);
      expect(legacy.getElement()).to.equal(null);
      expect(() => legacy.subscribeChange(() => {})).to.throw().with.property('code', 'unsupported_change_observation');
      JoomlaEditor.register(legacy);
      expect(JoomlaEditor.get(textarea.id)).to.equal(legacy);
      expect(legacy.setValue('Local third-party work')).to.equal(legacy);
    });
    cy.get('[data-draft-state="unavailable"]').should('be.visible');
    cy.tick(60000);
    cy.get('#jform_articletext').should('have.value', 'Local third-party work');
    cy.then(() => expect(writes).to.have.length(0));
  });
});
