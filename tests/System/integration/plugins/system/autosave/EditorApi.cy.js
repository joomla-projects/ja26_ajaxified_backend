/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Public editor contracts using the installed None provider', () => {
  let application;
  let api;
  let editor;
  let userId;
  let disposers;

  beforeEach(() => {
    userId = null;
    application = null;
    api = null;
    editor = null;
    disposers = [];
    const username = `autosave-api-${Date.now()}-${Cypress._.random(10000, 99999)}`;
    cy.db_createUser({ username, email: `${username}@example.com`, group_id: 8, params: '{"editor":"none","allowTourAutoStart":0}' })
      .then((id) => { userId = Number(id); });
    cy.doAdministratorLogin(username, 'test', false);
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    cy.window().then(async (win) => {
      application = win;
      api = (await win.Function('return import("editor-api")')()).JoomlaEditor;
    });
    cy.wrap(null).should(() => {
      editor = api.get('jform_articletext');
      expect(editor).to.not.equal(false);
      expect(editor.getType()).to.equal('none');
    });
    // Dispose autosave so assertions measure the public API independently of its consumers.
    cy.window().then((win) => win.dispatchEvent(new win.Event('pagehide')));
  });

  afterEach(() => {
    disposers.forEach((dispose) => dispose());
    if (userId) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE owner_id = ${userId}`);
  });

  it('observes native changes without reading or exposing content and releases one shared provider hook', () => {
    cy.then(() => {
      const read = cy.spy(editor, 'getValue');
      const observe = cy.spy(editor, 'observeChanges');
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
      const signal = () => editor.getElement().dispatchEvent(new application.Event('input', { bubbles: true }));
      expect(observe).to.have.been.calledOnce;
      signal();
      expect(first).to.equal(2);
      expect(second).to.equal(1);
      expect(read).to.not.have.been.called;
      offFirst();
      offFirst();
      signal();
      expect(first).to.equal(3);
      expect(second).to.equal(2);
      offDuplicate();
      offSecond();
      signal();
      expect(first).to.equal(3);
      expect(second).to.equal(2);
    });
  });

  it('compares programmatic values only while subscribed and keeps existing return and selection behavior', () => {
    cy.then(() => {
      const read = cy.spy(editor, 'getValue');
      expect(editor.setValue('Initial content')).to.equal(editor);
      expect(read).to.not.have.been.called;
      const changed = cy.spy();
      disposers.push(editor.subscribeChange(changed));
      editor.setValue('Changed content');
      expect(changed).to.have.been.calledOnce;
      expect(read).to.have.been.calledTwice;
      editor.setValue('Changed content');
      expect(changed).to.have.been.calledOnce;
      editor.getElement().setSelectionRange(0, 7);
      expect(editor.getSelection()).to.equal('Changed');
      expect(editor.replaceSelection('Selected')).to.equal(editor);
      expect(editor.getValue()).to.equal('Selected content');
      expect(changed).to.have.been.calledTwice;
      expect(() => editor.subscribeChange(null)).to.throw('callback must be a function');
    });
  });

  it('skips removed change subscribers and isolates their exceptions', () => {
    cy.then(() => {
      const reported = cy.stub(application, 'reportError');
      const removed = cy.spy();
      const later = cy.spy();
      let offRemoved;
      disposers.push(editor.subscribeChange(() => {
        offRemoved();
        throw new Error('Broken change consumer');
      }));
      offRemoved = editor.subscribeChange(removed);
      disposers.push(offRemoved, editor.subscribeChange(later));
      editor.getElement().dispatchEvent(new application.Event('input'));
      expect(removed).to.not.have.been.called;
      expect(later).to.have.been.calledOnce;
      expect(reported).to.have.been.calledOnce;
      expect(reported.firstCall.args[0].message).to.equal('Broken change consumer');
    });
  });

  it('keeps duplicate registry operations and independent lifecycle subscriptions idempotent', () => {
    cy.then(() => {
      const first = [];
      const second = [];
      const offFirst = api.subscribeLifecycle((detail) => first.push(detail.type));
      disposers.push(offFirst, api.subscribeLifecycle((detail) => {
        expect(Object.isFrozen(detail)).to.equal(true);
        expect(Object.keys(detail).sort()).to.deep.equal(['editorType', 'id', 'type']);
        expect(api.get(detail.id)).to.equal(detail.type === 'registered' ? editor : false);
        second.push(detail.type);
      }));
      api.unregister(editor);
      api.unregister(editor);
      expect(api.register(editor)).to.equal(api);
      api.register(editor);
      offFirst();
      offFirst();
      api.setActive(editor);
      api.unregister(editor);
      expect(api.getActive()).to.equal(null);
      expect(first).to.deep.equal(['unregistered', 'registered']);
      expect(second).to.deep.equal(['unregistered', 'registered', 'unregistered']);
      expect(() => api.subscribeLifecycle(undefined)).to.throw('callback must be a function');
    });
  });

  it('skips removed lifecycle subscribers and isolates errors after the registry mutation', () => {
    cy.then(() => {
      const reported = cy.stub(application, 'reportError');
      const removed = cy.spy();
      const later = cy.spy();
      let offRemoved;
      disposers.push(api.subscribeLifecycle(() => {
        offRemoved();
        throw new Error('Broken lifecycle consumer');
      }));
      offRemoved = api.subscribeLifecycle(removed);
      disposers.push(offRemoved, api.subscribeLifecycle(later));
      api.unregister(editor);
      expect(api.get(editor.getId())).to.equal(false);
      expect(application.Joomla.editors.instances[editor.getId()]).to.equal(undefined);
      expect(removed).to.not.have.been.called;
      expect(later).to.have.been.calledOnce;
      expect(reported).to.have.been.calledOnce;
    });
  });
});
