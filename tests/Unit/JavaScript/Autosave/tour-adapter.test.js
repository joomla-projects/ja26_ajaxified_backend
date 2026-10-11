/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import TourAutosaveAdapter, { validatePayload } from '../../../../media_source/com_guidedtours/src/tour-autosave-adapter.es6.js';

class Control extends EventTarget {
  constructor(value, checked = false) {
    super(); this.value = value; this.checked = checked; this.isConnected = true;
  }
}

class Editor {
  constructor(value) { this.value = value; this.listeners = new Set(); }
  getValue() { return this.value; }
  setValue(value) { this.value = value; this.listeners.forEach((listener) => listener()); }
  subscribeChange(listener) { this.listeners.add(listener); return () => this.listeners.delete(listener); }
}

const payload = () => ({
  title: '', uid: 'tour-uid', description: '<p>Draft</p>', note: 'Note', url: 'https://', autostart: 0,
});

const fixture = () => {
  const source = payload();
  const fields = Object.fromEntries(['title', 'uid', 'description', 'note', 'url'].map((key) => [key, new Control(source[key])]));
  fields.description.id = 'jform_description';
  fields.autostart = [new Control('0', true), new Control('1')];
  const controls = new Set(Object.values(fields).flat());
  const form = { isConnected: true, contains: (control) => controls.has(control) };
  const editor = new Editor(source.description);
  const adapter = new TourAutosaveAdapter({
    descriptor: { context: 'com_guidedtours.tour', targetId: '42', payloadSchemaVersion: 1 },
    form, fields, editor, getCurrentEditor: () => editor,
  });
  return { adapter, editor, fields, form };
};

test('Guided Tour captures exactly six immutable-source fields through the editor API', () => {
  const { adapter } = fixture();
  const captured = adapter.capture();
  assert.deepEqual(captured, payload());
  assert.deepEqual(Object.keys(captured), ['title', 'uid', 'description', 'note', 'url', 'autostart']);
  captured.title = 'mutated';
  assert.equal(adapter.capture().title, '');
});

test('Guided Tour restoration applies text, editor and strict radio values without dirty callbacks', () => {
  const { adapter, editor, fields } = fixture();
  let dirty = 0;
  adapter.initializeBaseline();
  adapter.subscribe(() => { dirty += 1; });
  const restored = { ...payload(), title: 'Restored', description: '<p>Restored</p>', url: 'incomplete:', autostart: 1 };
  adapter.apply(restored);
  assert.deepEqual(adapter.capture(), restored);
  assert.equal(editor.getValue(), '<p>Restored</p>');
  assert.equal(fields.autostart[1].checked, true);
  assert.equal(dirty, 0);
});

test('Guided Tour payload rejects missing, extra, malformed and overlong values', () => {
  assert.deepEqual(validatePayload(payload()), payload());
  const missing = payload(); delete missing.note;
  for (const invalid of [
    missing,
    { ...payload(), published: 1 },
    { ...payload(), title: 'x'.repeat(256) },
    { ...payload(), description: 'x'.repeat(65536) },
    { ...payload(), autostart: '1' },
    { ...payload(), autostart: 2 },
  ]) assert.throws(() => validatePayload(invalid), /payload is invalid/);
});

test('Guided Tour adapter observes editor and controls, then tears down idempotently', () => {
  const { adapter, editor, fields, form } = fixture();
  let dirty = 0;
  adapter.initializeBaseline();
  adapter.subscribe(() => { dirty += 1; });
  editor.setValue('<p>Changed</p>');
  fields.note.value = 'Changed'; fields.note.dispatchEvent(new Event('input'));
  assert.equal(dirty, 2);
  form.isConnected = false;
  assert.throws(() => adapter.capture(), /no longer current/);
  form.isConnected = true;
  adapter.destroy(); adapter.destroy();
  editor.setValue('late'); fields.note.dispatchEvent(new Event('input'));
  assert.equal(dirty, 2);
});
