/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import DraftRuntime, { creationRecovery } from 'com_autosave.drafts';
import { JoomlaEditor } from 'editor-api';
import JoomlaDialog from 'joomla.dialog';
import { availableControl, readControl, restoreControl } from './draft-controls.es6.js';

const sameValue = (left, right) => (Array.isArray(left) && Array.isArray(right)
  ? left.length === right.length && left.every((value, index) => value === right[index])
  : left === right);

/**
 * Native controls and explicit recovery UI. Only opaque creation references use history state.
 */
export default function mountDraftForm(form, configuration, editors = JoomlaEditor) {
  const ui = form.ownerDocument.getElementById(configuration.statusId) || form.querySelector('[data-draft-ui]');
  if (!ui?.matches('[data-draft-ui]')) return () => {};
  const nodes = (selector) => Array.from(new Set([
    ...ui.querySelectorAll(selector), ...form.querySelectorAll(selector),
  ]));
  const node = (selector) => nodes(selector)[0];
  const feedback = node('[data-draft-feedback]');
  const updateFeedback = () => {
    feedback.hidden = Array.from(feedback.children).every((element) => element.hidden);
  };
  const fields = {};
  const disposers = [];
  let runtime;
  let destroyed = false;
  let recovery = null;
  let recoveryRevision = null;
  let recoveredSnapshot = null;
  let actionRevision = 0;
  let nextPage = null;
  let creation = null;
  let initialized = false;

  const render = ({ state, code, lastPreserved }) => {
    if (creation && ((state === 'preserving' && !creation.attempted())
      || (lastPreserved && !creation.acknowledged()))) {
      state = 'unavailable';
      code = 'creation_recovery_unavailable';
    }
    nodes('[data-draft-error]').forEach((element) => {
      element.hidden = !element.dataset.draftError.split(' ').includes(code);
    });
    nodes('[data-draft-state]').forEach((element) => {
      element.hidden = element.dataset.draftState !== state;
    });
    node('[data-draft-status]').setAttribute('aria-busy', String(['detecting', 'preserving'].includes(state)));
    updateFeedback();
    node('[data-draft-action="retry"]').hidden = state !== 'offline';
  };

  if (!configuration.available) {
    render({ state: 'unavailable', code: configuration.errorCode });
    return () => {};
  }

  creation = creationRecovery(configuration);
  if (creation === false) {
    render({ state: 'unavailable', code: 'creation_recovery_unavailable' });
    return () => {};
  }

  const coverage = () => {
    const available = Object.entries(fields).filter(([, record]) => record.available);
    const labels = available.map(([, record]) => record.descriptor.label).join(', ');
    const description = node('[data-draft-scope]');
    description.textContent = description.dataset.description + (labels ? ' ' + labels + '.' : '');
    node('[data-draft-status]').title = description.textContent;
    // The declared selected-field scope is explained in help/recovery; warn only about missing controls.
    node('[data-draft-partial]').hidden = available.length === Object.keys(configuration.fields).length
      && !configuration.unsupported?.length && !configuration.excluded?.length;
    updateFeedback();
  };

  const editable = (element) => element && form.contains(element) && !element.disabled
    && !element.readOnly && element.type !== 'password' && element.type !== 'file';

  const currentElement = (descriptor) => {
    const matches = Array.from(form.querySelectorAll('[id]')).filter((element) => element.id === descriptor.id);
    return matches.length === 1 ? matches[0] : null;
  };

  // Compare only the signalled field; full snapshots remain in the serialized writer.
  const changedRecord = (record) => {
    if (!record.available) return;
    try {
      const value = record.descriptor.control === 'editor'
        ? record.editor.getValue()
        : readControl(record.element, record.descriptor);
      if (typeof value !== 'string' && !Array.isArray(value)) throw new Error('Unsupported native value');
      if (sameValue(value, record.value)) return;
      record.value = Array.isArray(value) ? [...value] : value;
      runtime.changed();
    } catch (error) {
      runtime.blocked = true;
      runtime.stopTimers();
      runtime.status('unavailable', 'unsupported_controls');
    }
  };

  const observeEditor = (record) => {
    const element = currentElement(record.descriptor);
    const editor = editors.get(record.descriptor.id);
    let eligible = false;
    try {
      eligible = form.isConnected && editable(element) && element.tagName === 'TEXTAREA'
        && element.form === form && element.name === record.descriptor.name
        && typeof editors.subscribeLifecycle === 'function' && editor
        && typeof editor.getValue === 'function' && typeof editor.setValue === 'function'
        && typeof editor.subscribeChange === 'function' && typeof editor.getElement === 'function'
        && editor.getElement() === element;
    } catch (error) {
      // A provider unable to establish ownership cannot protect this control.
      eligible = false;
    }
    if (eligible && record.available && record.element === element && record.editor === editor && record.dispose) return;
    record.dispose?.();
    record.dispose = null;
    record.element = element;
    record.editor = editor;
    record.available = false;
    if (eligible) {
      try {
        const value = editor.getValue();
        if (typeof value !== 'string') throw new Error('Unsupported editor value');
        const disposer = editor.subscribeChange(() => {
          if (record.available && record.element === element && record.editor === editor
            && editors.get(record.descriptor.id) === editor && editable(element)) changedRecord(record);
        });
        if (typeof disposer === 'function') {
          record.dispose = disposer;
          record.available = true;
          // First initialization uses the native serialization, never a replacement's value.
          if (!record.observed && !record.beforeEditorDirty && element === record.initialElement) {
            record.initialValue = value;
            record.value = value;
          }
          record.observed = true;
        }
      } catch (error) {
        record.available = false;
      }
    }
    coverage();
    if (record.observed && !record.available && runtime && !runtime.failed) {
      runtime.blocked = true;
      runtime.stopTimers();
      runtime.status('unavailable', 'unsupported_controls');
    } else if (record.available && initialized) {
      runtime.resumeControls();
      changedRecord(record);
      runtime.schedule();
    }
  };

  const capture = () => {
    const payload = {};
    Object.entries(fields).forEach(([key, record]) => {
      if ((record.available || record.observed) && (!editable(record.element) || !record.available)) {
        throw new Error('A previously protected control is unavailable');
      }
      if (record.available) {
        if (record.descriptor.control === 'editor') payload[key] = record.editor.getValue();
        else payload[key] = readControl(record.element, record.descriptor);
        if (typeof payload[key] !== 'string' && !Array.isArray(payload[key])) throw new Error('Unsupported native value');
      }
    });
    if (Object.keys(payload).length === 0) throw new Error('No observable protected controls');
    return payload;
  };

  runtime = new DraftRuntime({
    binding: { ...configuration, creationSources: creation?.sources },
    capture,
    baseline: () => Object.fromEntries(Object.entries(fields).filter(([, record]) => record.available)
      .map(([key, record]) => [key, record.initialValue])),
    onState: render,
  });
  Object.entries(configuration.fields).forEach(([key, descriptor]) => {
    const element = currentElement(descriptor);
    const initialValue = element ? readControl(element, descriptor) : undefined;
    fields[key] = {
      descriptor,
      element,
      initialElement: element,
      initialValue,
      value: Array.isArray(initialValue) ? [...initialValue] : initialValue,
      available: editable(element) && availableControl(element, descriptor),
      observed: descriptor.control !== 'editor' && editable(element) && availableControl(element, descriptor),
    };
    if (descriptor.control === 'editor') {
      observeEditor(fields[key]);
    }
  });
  initialized = true;
  runtime.initialProjection = Object.fromEntries(Object.entries(fields).filter(([, record]) => record.available)
    .map(([key, record]) => [key, record.initialValue]));
  coverage();
  if (!Object.values(fields).some((record) => record.available)) {
    runtime.blocked = true;
    runtime.status('unavailable', 'unsupported_controls');
  }
  const listen = (target, event, callback, options) => {
    target.addEventListener(event, callback, options);
    disposers.push(() => target.removeEventListener(event, callback, options));
  };
  const changedControl = (event) => {
    Object.values(fields).forEach((record) => {
      if (!(record.element === event.target || record.element?.contains(event.target))) return;
      if (record.descriptor.control === 'editor' && !record.observed) {
        // Keep edits made before the asynchronous native editor becomes available.
        if (event.type === 'input' && editable(record.element)) {
          record.beforeEditorDirty = !sameValue(readControl(record.element, record.descriptor), record.initialValue);
        }
        return;
      }
      changedRecord(record);
    });
  };
  listen(form, 'input', changedControl);
  listen(form, 'change', changedControl);
  if (typeof editors.subscribeLifecycle === 'function') disposers.push(editors.subscribeLifecycle((detail) => {
    Object.values(fields).filter((record) => record.descriptor.control === 'editor'
      && record.descriptor.id === detail.id).forEach(observeEditor);
  }));

  /**
   * Joomla.submitform produces a native submit event. Optional metadata never prevents it.
   */
  listen(form, 'submit', () => {
    form.querySelectorAll('[data-draft-metadata]').forEach((element) => element.remove());
    const task = form.elements.namedItem('task')?.value;
    if (!configuration.saveTasks?.includes(task)) return;
    try {
      const submitted = runtime.submitted();
      if (submitted === null) return;
      const metadata = { draft_id: configuration.draftId, revision: String(submitted), ...recoveredSnapshot };
      Object.entries(metadata).forEach(([key, value]) => {
        const hidden = form.ownerDocument.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'autosave[' + key + ']';
        hidden.value = value;
        hidden.setAttribute('data-draft-metadata', '');
        form.appendChild(hidden);
      });
    } catch (error) {
      // Ordinary submission remains available even with unsupported or unavailable autosave.
    }
  }, true);

  const select = node('[data-draft-candidates]');
  const restoreButton = node('[data-draft-action="restore"]');
  const showRecovery = (value) => {
    const section = node('[data-draft-recovery]');
    const focused = section.contains(form.ownerDocument.activeElement);
    section.hidden = !value;
    if (!value && focused) node('[data-draft-status]').focus();
  };
  const resetReview = () => {
    actionRevision += 1;
    recovery = null;
    restoreButton.disabled = true;
    node('[data-draft-comparison]').replaceChildren();
    node('[data-draft-comparison-container]').hidden = true;
  };
  listen(select, 'change', resetReview);

  const refresh = async (append = false) => {
    const token = actionRevision;
    const result = await runtime.candidates(append ? nextPage : null);
    if (destroyed || token !== actionRevision) return;
    if (!append) select.replaceChildren();
    result.candidates.forEach((candidate) => {
      const option = form.ownerDocument.createElement('option');
      option.value = candidate.draft_id;
      option.textContent = new Intl.DateTimeFormat(configuration.locale, {
        dateStyle: 'medium', timeStyle: 'short', timeZone: configuration.timeZone,
      }).format(new Date(candidate.updated_at));
      select.appendChild(option);
    });
    nextPage = result.next ?? null;
    node('[data-draft-action="more"]').hidden = nextPage === null;
    if (!append) {
      resetReview();
      showRecovery(select.options.length > 0);
    }
  };

  const confirm = (button) => JoomlaDialog.confirm(button.dataset.confirmMessage, button.dataset.confirmTitle);

  const onAction = async (event) => {
    const button = event.target.closest('[data-draft-action]');
    if (!button || button.disabled) return;
    const action = button.dataset.draftAction;
    const sourceId = select.value;
    const token = ++actionRevision;
    button.disabled = true;
    try {
      if (action === 'retry') {
        if (runtime.failed || runtime.pending) await runtime.retry();
        else await refresh();
      } else if (action === 'more' && nextPage !== null) {
        await refresh(true);
      } else if (action === 'keep') {
        showRecovery(false);
        resetReview();
      } else if (action === 'review') {
        button.disabled = true;
        const result = await runtime.read(sourceId);
        if (destroyed || token !== actionRevision || sourceId !== select.value) return;
        recovery = result;
        recoveryRevision = runtime.revision;
        node('[data-draft-conflict]').hidden = !result.conflict;
        node('[data-draft-creation]').hidden = !result.uncertain_creation;
        const comparison = node('[data-draft-comparison]');
        comparison.replaceChildren();
        Object.entries(result.payload).forEach(([key, value]) => {
          const row = form.ownerDocument.createElement('tr');
          const label = form.ownerDocument.createElement('th');
          label.scope = 'row';
          label.textContent = fields[key]?.descriptor.label || key;
          row.appendChild(label);
          [result.canonical[key] || '', value].forEach((text) => {
            const cell = form.ownerDocument.createElement('td');
            const content = form.ownerDocument.createElement('pre');
            content.className = 'mb-0 text-wrap text-break';
            content.textContent = fields[key]?.descriptor.control === 'checkbox'
              ? Joomla.Text._(text === '1' ? 'JYES' : 'JNO')
              : (Array.isArray(text) ? text.join(', ') : text);
            cell.appendChild(content);
            row.appendChild(cell);
          });
          comparison.appendChild(row);
        });
        node('[data-draft-comparison-container]').hidden = false;
        restoreButton.disabled = false;
      } else if (action === 'restore' && recovery) {
        if (runtime.revision !== recoveryRevision && !(await confirm(button))) return;
        if (destroyed || token !== actionRevision || sourceId !== select.value) return;
        const omitted = [...recovery.omitted];
        Object.entries(recovery.payload).forEach(([key, value]) => {
          const record = fields[key];
          if (!record?.available || !editable(record.element)) {
            omitted.push(key);
            return;
          }
          try {
            if (record.descriptor.control === 'editor') record.editor.setValue(value);
            else restoreControl(record.element, record.descriptor, value);
            record.element.dispatchEvent(new Event('change', { bubbles: true }));
          } catch (error) {
            omitted.push(key);
          }
        });
        recoveredSnapshot = omitted.length === 0 && Number.isSafeInteger(recovery.source_revision) && recovery.source_revision > 0
          ? { source_id: sourceId, source_revision: String(recovery.source_revision) }
          : null;
        node('[data-draft-partial-recovery]').hidden = omitted.length === 0;
        updateFeedback();
        runtime.changed();
        await runtime.flush();
        // Keep the source until native success or explicit discard; partial recovery never retires it.
        if (!destroyed && token === actionRevision) {
          showRecovery(false);
          resetReview();
        }
      } else if (action === 'discard') {
        if (!(await confirm(button)) || destroyed || token !== actionRevision
          || sourceId !== select.value) return;
        await runtime.discard(sourceId);
        if (recoveredSnapshot?.source_id === sourceId) recoveredSnapshot = null;
        if (!destroyed && token === actionRevision) await refresh();
      }
    } catch (error) {
      // Retry/detection already owns its status, including another interrupted request.
      if (action !== 'retry' && !destroyed && token === actionRevision && error.name !== 'AbortError') {
        runtime.status(error.code === 'base_revision_conflict' ? 'conflict' : 'unavailable', error.code);
      }
    } finally {
      if (!destroyed) button.disabled = action === 'restore' && recovery === null;
    }
  };
  listen(form, 'click', onAction);
  if (!form.contains(ui)) listen(ui, 'click', onAction);

  refresh().catch(() => {
    // Detection owns its status; a late failure must not overwrite acknowledged preservation.
  });
  const dispose = () => {
    if (destroyed) return;
    render({ state: 'unavailable', code: 'disposed_form', lastPreserved: runtime.lastPreserved });
    form.querySelectorAll('[data-draft-metadata]').forEach((element) => element.remove());
    nodes('[data-draft-action]').forEach((button) => { button.disabled = true; });
    destroyed = true;
    actionRevision += 1;
    runtime.destroy();
    Object.values(fields).forEach((record) => record.dispose?.());
    disposers.forEach((release) => release());
  };
  listen(globalThis.window, 'pagehide', (event) => {
    if (event.persisted) {
      // A cached document has an old writer and possibly expired access. Obtain a fresh native form.
      globalThis.window.addEventListener('pageshow', (restored) => {
        if (restored.persisted) globalThis.window.location.reload();
      }, { once: true });
    }
    dispose();
  });
  return dispose;
}
