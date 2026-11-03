/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

['none', 'codemirror', 'tinymce'].forEach((provider) => {
  describe(`Autosave with the installed ${provider} editor`, () => {
    let userId;
    let api;
    let editor;
    let application;
    let disposers;
    let writes;
    let articleTitle;

    const editorReady = () => {
      cy.get('#item-form').should('be.visible');
      cy.window().should((win) => {
        expect(win.Joomla.getOptions('com_autosave.forms')).to.have.property('item-form');
        expect(win.Joomla.getOptions('com_autosave.forms')['item-form'].available).to.equal(true);
      }).then(async (win) => {
        application = win;
        // Resolve the installed provider through Joomla's actual browser import map.
        api = (await win.Function('return import("editor-api")')()).JoomlaEditor;
      });
      cy.wrap(null).should(() => {
        editor = api.get('jform_articletext');
        expect(editor).to.not.equal(false);
        expect(editor.getType()).to.equal(provider);
        expect(editor.getElement()).to.equal(application.document.getElementById('jform_articletext'));
      });
    };

    const openEditor = (articleData = null) => {
      if (articleData) {
        cy.db_createArticle(articleData).then((article) => {
          cy.visit('/administrator/index.php?option=com_content&view=articles');
          cy.searchForItem(`id:${article.id}`);
          cy.get(`a[href*="task=article.edit"][href*="id=${article.id}"]`).should('have.length', 1).click();
        });
      } else {
        cy.visit('/administrator/index.php?option=com_content&task=article.add');
      }
      editorReady();
      cy.wait('@detect').its('response.body.success').should('equal', true);
    };

    beforeEach(() => {
      userId = null;
      api = null;
      editor = null;
      application = null;
      disposers = [];
      writes = [];
      const username = `autosave-${provider}-${Date.now()}-${Cypress._.random(10000, 99999)}`;
      articleTitle = `Autosave editor ${username}`;
      cy.db_createUser({
        username, email: `${username}@example.com`,
        group_id: 8, params: JSON.stringify({ editor: provider, allowTourAutoStart: 0 }),
      }).then((id) => { userId = Number(id); });
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
        if (task === 'draft.detect') request.alias = 'detect';
        if (task === 'draft.preserve') {
          request.alias = 'preserve';
          writes.push(request.body);
        }
      });
    });

    [false, true].forEach((creating) => {
      it(`keeps unchanged native serialization clean and protects clearing (${creating ? 'new' : 'existing'})`, () => {
        openEditor(creating ? null : { title: articleTitle, introtext: '<p></p>' });
        cy.get('[data-draft-state="clean"]').should('be.visible');
        cy.clock(Date.now(), ['setTimeout', 'clearTimeout', 'Date']);
        cy.window().then((win) => {
          const current = win.Joomla.editors.instances.jform_articletext;
          current.setValue(current.getValue());
          current.getElement().dispatchEvent(new win.Event('input', { bubbles: true }));
          current.getElement().dispatchEvent(new win.Event('change', { bubbles: true }));
        });
        cy.tick(60000);
        cy.then(() => expect(writes).to.have.length(0));
        cy.window().then((win) => win.Joomla.editors.instances.jform_articletext.setValue('Genuine editor work'));
        cy.tick(650);
        cy.wait('@preserve').its('response.body.success').should('equal', true);
        // The writer must accept this snapshot before the next edit advances its debounce clock.
        cy.get('[data-draft-state="preserved"]').should('be.visible');
        cy.window().then((win) => win.Joomla.editors.instances.jform_articletext.setValue(''));
        cy.tick(650);
        cy.wait('@preserve').its('request.body.payload.articletext').should('equal', '');
        cy.get('[data-draft-state="preserved"]').should('be.visible');
        cy.tick(60000);
        cy.then(() => expect(writes).to.have.length(2));
      });
    });

    afterEach(() => {
      disposers.forEach((dispose) => dispose());
      if (userId) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${userId}`);
      if (articleTitle) cy.task('queryDB', `DELETE FROM #__content WHERE title = '${articleTitle}'`);
    });

    it('keeps independent subscriptions, programmatic changes and idempotent cleanup', () => {
      openEditor();
      cy.then(() => {
        let first = 0;
        let second = 0;
        const callback = (...args) => {
          expect(args).to.deep.equal([]);
          first += 1;
        };
        const offFirst = editor.subscribeChange(callback);
        const offDuplicate = editor.subscribeChange(callback);
        const offSecond = editor.subscribeChange(() => {
          second += 1;
        });
        disposers.push(offFirst, offDuplicate, offSecond);
        expect(editor.setValue('First editor value')).to.equal(editor);
        const normalized = editor.getValue();
        expect(first).to.equal(2);
        expect(second).to.equal(1);
        editor.setValue(normalized);
        expect(first).to.equal(2);
        offFirst();
        offFirst();
        editor.setValue('Second editor value');
        expect(first).to.equal(3);
        expect(second).to.equal(2);
        offDuplicate();
        offSecond();
        editor.replaceSelection('Inserted content');
        expect(first).to.equal(3);
        expect(second).to.equal(2);
      });
    });

    it('reports replacement after registry mutation and rejects stale unregister and subscriptions', () => {
      openEditor();
      cy.then(() => {
        const events = [];
        let staleCalls = 0;
        let liveCalls = 0;
        disposers.push(api.subscribeLifecycle((detail) => {
          if (detail.id !== editor.getId()) return;
          expect(Object.isFrozen(detail)).to.equal(true);
          expect(Object.keys(detail).sort()).to.deep.equal(['editorType', 'id', 'type']);
          events.push({ type: detail.type, current: api.get(detail.id), legacy: application.Joomla.editors.instances[detail.id] });
        }));
        const offOld = editor.subscribeChange(() => {
          staleCalls += 1;
        });
        disposers.push(offOld);
        // Both decorators use the real installed provider; CodeMirror keeps its native bridge.
        const replacement = new editor.constructor(editor.getRawInstance(), provider,
          editor.getId(), editor.changeObserver, editor.getElement());
        api.register(editor);
        api.register(replacement);
        disposers.push(replacement.subscribeChange(() => {
          liveCalls += 1;
        }));
        offOld();
        offOld();
        api.unregister(editor);
        replacement.setValue('Replacement content');
        expect(api.get(editor.getId())).to.equal(replacement);
        expect(staleCalls).to.equal(0);
        expect(liveCalls).to.equal(1);
        expect(events.map(({ type }) => type)).to.deep.equal(['unregistered', 'registered']);
        expect(events[0].current).to.equal(false);
        expect(events[0].legacy).to.equal(undefined);
        expect(events[1].current).to.equal(replacement);
        expect(events[1].legacy).to.equal(replacement);
        api.unregister(replacement);
        api.unregister(replacement);
        replacement.setValue('After unregister');
        expect(liveCalls).to.equal(1);
      });
    });

    [false, true].forEach((creating) => {
      it(`retires only submitted changes through native Apply and Save & Close (${creating ? 'new' : 'existing'})`, () => {
        openEditor(creating ? null : { title: articleTitle });
        cy.get('#jform_title').clear();
        cy.get('#jform_title').type(articleTitle);
        cy.window().then((win) => {
          win.Joomla.editors.instances.jform_articletext.setValue('Saved through the native editor');
        });
        cy.get('[data-draft-state="preserved"]').should('be.visible');
        cy.window().then((win) => {
          const id = win.Joomla.getOptions('com_autosave.forms')['item-form'].draftId;
          cy.wrap(id).as('submittedDraft');
        });
        cy.clickToolbarButton('Save');
        cy.checkForSystemMessage('Article saved.');
        cy.wait('@detect').its('response.body.data.candidates').should('have.length', 0);
        cy.get('[data-draft-recovery]').should('not.be.visible');
        cy.get('@submittedDraft').then((id) => cy.task('queryDB', `SELECT payload, retired_revision FROM #__autosave_drafts WHERE draft_id = '${id}'`))
          .then((rows) => {
            expect(rows[0].payload).to.equal(null);
            expect(Number(rows[0].retired_revision)).to.be.greaterThan(0);
          });
        cy.window().then((win) => {
          const current = win.Joomla.editors.instances.jform_articletext;
          current.setValue(current.getValue());
        });
        cy.get('[data-draft-state="clean"]').should('be.visible');
        cy.window().then((win) => win.Joomla.editors.instances.jform_articletext.setValue('Later saved editor content'));
        cy.get('[data-draft-state="preserved"]').should('be.visible');
        cy.clickToolbarButton('Save & Close');
        cy.checkForSystemMessage('Article saved.');
        cy.then(() => cy.task('queryDB', `SELECT introtext FROM #__content WHERE title = '${articleTitle}'`)).then((rows) => {
          expect(rows).to.have.length(1);
          expect(rows[0].introtext).to.contain('Later saved editor content');
        });
      });
    });

    it('observes real typing and undo, without treating selection alone as an edit', () => {
      openEditor();
      const changes = [];
      cy.then(() => {
        disposers.push(editor.subscribeChange((...args) => changes.push(args)));
      });
      if (provider === 'none') cy.get('#jform_articletext').type('Typed editor content');
      if (provider === 'codemirror') cy.get('.cm-content').type('Typed editor content');
      if (provider === 'tinymce') {
        cy.get('#jform_articletext_ifr').its('0.contentDocument.body').then((body) => cy.wrap(body).type('Typed editor content'));
      }
      cy.wrap(changes).should('have.length.greaterThan', 0);
      cy.wait('@preserve').its('response.body.success').should('equal', true);
      cy.then(() => {
        expect(changes.every((args) => args.length === 0)).to.equal(true);
        if (provider === 'codemirror') {
          const count = changes.length;
          editor.getRawInstance().dispatch({ selection: { anchor: 0 } });
          expect(changes).to.have.length(count);
        }
        if (provider === 'tinymce') {
          const native = editor.getRawInstance();
          const before = editor.getValue();
          native.undoManager.transact(() => editor.setValue('Undoable editor content'));
          const count = changes.length;
          expect(native.undoManager.hasUndo()).to.equal(true);
          native.undoManager.undo();
          expect(editor.getValue()).to.equal(before);
          expect(changes.length).to.be.greaterThan(count);
        }
      });
    });
  });
});
