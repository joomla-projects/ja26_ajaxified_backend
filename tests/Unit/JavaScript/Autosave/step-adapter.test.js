/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import StepAutosaveAdapter, { validatePayload } from '../../../../media_source/com_guidedtours/src/step-autosave-adapter.es6.js';

class Control extends EventTarget {
  constructor(id, value, checked = false) { super(); this.id = id; this.value = value; this.checked = checked; this.isConnected = true; }
}

class Editor {
  constructor(value) { this.value = value; this.listeners = new Set(); }
  getValue() { return this.value; }
  setValue(value) { this.value = value; this.listeners.forEach((listener) => listener()); }
  subscribeChange(listener) { this.listeners.add(listener); return () => this.listeners.delete(listener); }
}

const payload = () => ({ position: 'center', target: '', title: '', description: '<p>Draft</p>', type: 2, url: '', interactive_type: 1, note: '', required: 1, requiredvalue: '' });

const fixture = () => {
  const source = payload();
  const fields = Object.fromEntries(['position', 'target', 'title', 'type', 'url', 'interactive_type', 'note', 'requiredvalue'].map((key) => [key, new Control(`jform_${key}`, String(source[key]))]));
  fields.description = new Control('jform_description', '');
  fields.required = [new Control('jform_params_required0', '0'), new Control('jform_params_required1', '1', true)];
  const controls = new Set(Object.values(fields).flat());
  const form = { isConnected: true, contains: (control) => controls.has(control) };
  const editor = new Editor(source.description);
  const adapter = new StepAutosaveAdapter({ descriptor: { context: 'com_guidedtours.step', targetId: '42', payloadSchemaVersion: 1 }, form, fields, editor, getCurrentEditor: () => editor });
  return { adapter, editor, fields, form };
};

test('Guided Tour Step captures the exact typed payload through the editor API', () => {
  const { adapter } = fixture();
  const captured = adapter.capture();
  assert.deepEqual(captured, payload());
  assert.deepEqual(Object.keys(captured), ['position', 'target', 'title', 'description', 'type', 'url', 'interactive_type', 'note', 'required', 'requiredvalue']);
  captured.title = 'mutated'; assert.equal(adapter.capture().title, '');
});

test('Guided Tour Step restore applies controls, enums, radio and editor with dependent events', () => {
  const { adapter, editor, fields } = fixture(); let dirty = 0; let events = 0;
  Object.values(fields).flat().forEach((field) => ['input', 'change'].forEach((type) => field.addEventListener(type, () => { events += 1; })));
  adapter.initializeBaseline(); adapter.subscribe(() => { dirty += 1; });
  const restored = { ...payload(), position: 'left', type: 1, interactive_type: 6, required: 0, target: '#field', description: '<p>Restored</p>', requiredvalue: 'temporary' };
  adapter.apply(restored);
  assert.deepEqual(adapter.capture(), restored); assert.equal(editor.getValue(), '<p>Restored</p>'); assert.equal(fields.required[0].checked, true); assert.equal(dirty, 0); assert.ok(events > 0);
});

test('Guided Tour Step rejects missing, extra, malformed, loose enum and overlength values', () => {
  const missing = payload(); delete missing.note;
  for (const invalid of [missing, { ...payload(), tour_id: 8 }, { ...payload(), position: 'middle' }, { ...payload(), type: '2' }, { ...payload(), interactive_type: 7 }, { ...payload(), required: 2 }, { ...payload(), target: 'x'.repeat(256) }]) {
    assert.throws(() => validatePayload(invalid), /payload is invalid/);
  }
});

test('Guided Tour Step observes controls and editor then rejects stale dependencies', () => {
  const { adapter, editor, fields, form } = fixture(); let dirty = 0;
  adapter.initializeBaseline(); adapter.subscribe(() => { dirty += 1; });
  fields.type.value = '1'; fields.type.dispatchEvent(new Event('change')); editor.setValue('<p>Changed</p>'); assert.equal(dirty, 2);
  form.isConnected = false; assert.throws(() => adapter.capture(), /no longer current/);
  form.isConnected = true; adapter.destroy(); adapter.destroy();
});
