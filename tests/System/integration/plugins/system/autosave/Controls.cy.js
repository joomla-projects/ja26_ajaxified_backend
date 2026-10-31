/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

describe('Draft recovery through real browser controls', () => {
  let controls;
  let container;
  let document;
  let draftId;

  beforeEach(() => {
    cy.doAdministratorLogin();
    cy.visit('/administrator/index.php?option=com_content&task=article.add');
    cy.window().then(async (win) => {
      document = win.document;
      draftId = win.Joomla.getOptions('com_autosave.forms')['item-form'].draftId;

      controls = await win.Function('url', 'return import(url)')(new URL('media/com_autosave/js/draft-controls.js', win.Joomla.getOptions('system.paths').rootFull).href);
      container = document.createElement('div');
      document.body.append(container);
    });
  });

  afterEach(() => {
    container?.remove();
    if (draftId) cy.task('queryDB', `DELETE FROM #__autosave_drafts WHERE draft_id = '${draftId}'`);
  });

  it('rejects disabled radio choices and foreign checkbox values without losing current choices', () => {
    cy.then(() => {
      container.innerHTML = '<fieldset id="radios"><input type="radio" name="fixture-radio" value="0" checked><input type="radio" name="fixture-radio" value="1" disabled></fieldset>'
        + '<fieldset id="checks"><input type="checkbox" value="1" checked disabled><input type="checkbox" value="3"></fieldset>';
      const radios = container.querySelector('#radios');
      const checks = container.querySelector('#checks');
      expect(controls.readControl(radios, { control: 'radio' })).to.equal('0');
      expect(() => controls.restoreControl(radios, { control: 'radio' }, '1')).to.throw();
      expect(radios.firstElementChild.checked).to.equal(true);
      radios.lastElementChild.disabled = false;
      controls.restoreControl(radios, { control: 'radio' }, '1');
      expect(controls.readControl(radios, { control: 'radio' })).to.equal('1');
      controls.restoreControl(checks, { control: 'checkboxes' }, ['3']);
      expect(checks.firstElementChild.checked).to.equal(true);
      expect(controls.readControl(checks, { control: 'checkboxes' })).to.deep.equal(['3']);
      ['1', '99'].forEach((id) => expect(() => controls.restoreControl(checks, { control: 'checkboxes' }, [id])).to.throw());
    });
  });

  [false, true].forEach((enhanced) => {
    it(`replaces and clears ${enhanced ? 'Joomla Choices' : 'plain'} multiple selections exactly`, () => {
      cy.then(() => {
        const select = '<select multiple><option value="a" selected>A</option><option value="b">B</option><option value="disabled" disabled>Disabled</option></select>';
        container.innerHTML = enhanced ? `<joomla-field-fancy-select>${select}</joomla-field-fancy-select>` : select;
      });
      if (enhanced) cy.wrap(null).should(() => expect(container.firstElementChild.choicesInstance).to.be.an('object'));
      cy.then(() => {
        const select = container.querySelector('select');
        controls.restoreControl(select, { control: 'multiple' }, ['b']);
        expect(controls.readControl(select, { control: 'multiple' })).to.deep.equal(['b']);
        if (enhanced) expect(Array.from(container.firstElementChild.value)).to.deep.equal(['b']);
        [['disabled'], ['foreign'], ['b', 'foreign'], 'b'].forEach((value) => {
          expect(() => controls.restoreControl(select, { control: 'multiple' }, value)).to.throw();
          expect(controls.readControl(select, { control: 'multiple' })).to.deep.equal(['b']);
        });
        controls.restoreControl(select, { control: 'multiple' }, []);
        expect(controls.readControl(select, { control: 'multiple' })).to.deep.equal([]);
        if (enhanced) expect(Array.from(container.firstElementChild.value)).to.deep.equal([]);
      });
    });
  });

  it('retains a fancy selection when its replacement API is unavailable', () => {
    cy.then(() => {
      container.innerHTML = '<joomla-field-fancy-select><select multiple><option value="a" selected>A</option><option value="b">B</option></select></joomla-field-fancy-select>';
    });
    cy.wrap(null).should(() => expect(container.firstElementChild.choicesInstance).to.be.an('object'));
    cy.then(() => {
      const fancy = container.firstElementChild;
      const original = fancy.choicesInstance.removeActiveItems;
      try {
        fancy.choicesInstance.removeActiveItems = undefined;
        expect(() => controls.restoreControl(fancy.querySelector('select'), { control: 'multiple' }, ['b'])).to.throw();
        expect(Array.from(fancy.value)).to.deep.equal(['a']);
      } finally {
        fancy.choicesInstance.removeActiveItems = original;
      }
    });
  });

  [false, true].forEach((multiple) => {
    it(`uses native custom Choices values for ${multiple ? 'multiple' : 'single'} selection`, () => {
      cy.then(() => {
        container.innerHTML = `<joomla-field-fancy-select allow-custom><select ${multiple ? 'multiple' : ''}><option value="sidebar" selected>Sidebar</option></select></joomla-field-fancy-select>`;
      });
      cy.wrap(null).should(() => expect(container.firstElementChild.choicesInstance).to.be.an('object'));
      cy.then(() => {
        const fancy = container.firstElementChild;
        const select = fancy.querySelector('select');
        controls.restoreControl(select, { control: multiple ? 'multiple' : 'select' }, multiple ? ['custom-position'] : 'custom-position');
        expect(controls.readControl(select, { control: multiple ? 'multiple' : 'select' })).to.deep.equal(multiple ? ['custom-position'] : 'custom-position');
        fancy.removeAttribute('allow-custom');
        expect(() => controls.restoreControl(select, { control: multiple ? 'multiple' : 'select' }, multiple ? ['foreign'] : 'foreign')).to.throw();
      });
    });
  });

  it('uses the installed calendar parser and widget and clears both visible and alternate values', () => {
    cy.get('#jform_created').then((input) => {
      cy.window().then((win) => {
        const element = input[0];
        const native = win.JoomlaCalendar.getCalObject(element)._joomlaCalendar;
        native.setDate(new win.Date(2026, 9, 16, 12, 0, 0));
        native.callHandler();
        const parser = cy.spy(win.Date, 'parseFieldDate');
        const setDate = cy.spy(native, 'setDate');
        const handler = cy.spy(native, 'callHandler');
        const saved = element.value;
        expect(saved).to.not.equal('');
        expect(controls.availableControl(element, { control: 'calendar' })).to.equal(true);
        controls.restoreControl(element, { control: 'calendar' }, saved);
        expect(parser).to.have.been.calledOnce;
        expect(setDate).to.have.been.calledOnce;
        expect(handler).to.have.been.calledOnce;
        controls.restoreControl(element, { control: 'calendar' }, '');
        expect(element.value).to.equal('');
        expect(element.getAttribute('data-alt-value')).to.equal('');
      });
    });
  });
});
