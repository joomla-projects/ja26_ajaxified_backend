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

  const failCleanup = (provider, failure) => {
    const observe = provider.observeChanges;
    const cleanup = cy.stub().throws(failure);
    cy.stub(provider, 'observeChanges').callsFake((callback) => {
      const release = observe.call(provider, callback);
      return () => {
        release();
        cleanup();
      };
    });
    return cleanup;
  };

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

  ['reportError', 'queued error'].forEach((reporter) => {
    it(`reports a failing cleanup once through ${reporter} and allows observation to restart`, () => {
      cy.then(() => {
        const failure = new Error('Provider cleanup failed');
        let reported;
        if (reporter === 'reportError') {
          reported = cy.stub(application, 'reportError');
        } else {
          const original = application.reportError;
          application.reportError = undefined;
          disposers.push(() => {
            application.reportError = original;
          });
          reported = cy.stub(application, 'queueMicrotask');
        }
        const cleanup = failCleanup(editor, failure);
        const removed = cy.spy();
        const off = editor.subscribeChange(removed);
        disposers.push(off);
        expect(() => off()).to.not.throw();
        off();
        expect(cleanup).to.have.been.calledOnce;
        expect(reported).to.have.been.calledOnce;
        if (reporter === 'reportError') expect(reported.firstCall.args[0]).to.equal(failure);
        else expect(() => reported.firstCall.args[0]()).to.throw().and.equal(failure);

        editor.observeChanges.restore();
        const later = cy.spy();
        disposers.push(editor.subscribeChange(later));
        editor.getElement().dispatchEvent(new application.Event('input'));
        expect(removed).to.not.have.been.called;
        expect(later).to.have.been.calledOnce;
        expect(cleanup).to.have.been.calledOnce;
        expect(reported).to.have.been.calledOnce;
      });
    });
  });

  it('finishes unregistration and lifecycle notification after provider cleanup fails', () => {
    cy.then(() => {
      const failure = new Error('Provider cleanup failed');
      const reported = cy.stub(application, 'reportError');
      const cleanup = failCleanup(editor, failure);
      const changed = cy.spy();
      const lifecycle = cy.spy();
      disposers.push(editor.subscribeChange(changed), api.subscribeLifecycle(lifecycle));
      api.setActive(editor);
      expect(api.unregister(editor)).to.equal(api);
      api.unregister(editor);
      expect(api.get(editor.getId())).to.equal(false);
      expect(application.Joomla.editors.instances[editor.getId()]).to.equal(undefined);
      expect(api.getActive()).to.equal(null);
      expect(lifecycle).to.have.been.calledOnce;
      expect(lifecycle.firstCall.args[0]).to.deep.equal({
        type: 'unregistered', id: editor.getId(), editorType: 'none',
      });
      editor.getElement().dispatchEvent(new application.Event('input'));
      expect(changed).to.not.have.been.called;
      expect(cleanup).to.have.been.calledOnce;
      expect(reported).to.have.been.calledOnce;
      expect(reported.firstCall.args[0]).to.equal(failure);
    });
  });

  it('registers a replacement after failed cleanup and ignores stale teardown', () => {
    cy.then(() => {
      const failure = new Error('Provider cleanup failed');
      const reported = cy.stub(application, 'reportError');
      const cleanup = failCleanup(editor, failure);
      const removed = cy.spy();
      const transitions = [];
      disposers.push(editor.subscribeChange(removed), api.subscribeLifecycle((detail) => {
        transitions.push({
          type: detail.type, current: api.get(detail.id), legacy: application.Joomla.editors.instances[detail.id],
        });
      }));
      const replacement = new editor.constructor(editor.getRawInstance(), editor.getType(), editor.getId());
      disposers.push(() => {
        api.unregister(replacement);
        api.register(editor);
      });
      api.setActive(editor);
      expect(api.register(replacement)).to.equal(api);
      api.unregister(editor);
      expect(api.get(editor.getId())).to.equal(replacement);
      expect(api.getActive()).to.equal(null);
      expect(transitions).to.deep.equal([
        { type: 'unregistered', current: false, legacy: undefined },
        { type: 'registered', current: replacement, legacy: replacement },
      ]);
      const changed = cy.spy();
      disposers.push(replacement.subscribeChange(changed));
      replacement.setValue('Later editor work');
      expect(changed).to.have.been.calledOnce;
      expect(removed).to.not.have.been.called;
      expect(cleanup).to.have.been.calledOnce;
      expect(reported).to.have.been.calledOnce;
      expect(reported.firstCall.args[0]).to.equal(failure);
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
