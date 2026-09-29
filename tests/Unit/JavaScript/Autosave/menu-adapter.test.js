/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import MenuAutosaveAdapter, { validatePayload } from '../../../../media_source/com_menus/src/menu-autosave-adapter.es6.js';

class Control extends EventTarget {
  constructor(value) { super(); this.value = value; this.isConnected = true; }
}

const fixture = () => {
  const fields = { title: new Control('Menu'), description: new Control('Description') };
  const controls = new Set(Object.values(fields));
  const form = { isConnected: true, contains: (control) => controls.has(control) };
  return { fields, form, adapter: new MenuAutosaveAdapter({ descriptor: { context: 'com_menus.menu', targetId: '42', payloadSchemaVersion: 1 }, form, fields }) };
};

test('Menu captures exactly title and description as an immutable snapshot', () => {
  const { adapter } = fixture(); const payload = adapter.capture();
  assert.deepEqual(payload, { title: 'Menu', description: 'Description' });
  payload.title = 'changed'; assert.equal(adapter.capture().title, 'Menu');
});

test('Menu observes both fields and recovery dispatches input and change', () => {
  const { adapter, fields } = fixture(); let dirty = 0; let events = 0;
  Object.values(fields).forEach((field) => ['input', 'change'].forEach((type) => field.addEventListener(type, () => { events += 1; })));
  adapter.initializeBaseline(); adapter.subscribe(() => { dirty += 1; });
  fields.title.value = 'Changed'; fields.title.dispatchEvent(new Event('input'));
  fields.description.value = 'Changed'; fields.description.dispatchEvent(new Event('change'));
  assert.equal(dirty, 2);
  adapter.apply({ title: '', description: '' }); assert.equal(events, 6); assert.equal(dirty, 2);
});

test('Menu rejects missing, extra, non-string and overlength payloads', () => {
  for (const invalid of [{ title: '' }, { title: '', description: '', menutype: 'main' }, { title: [], description: '' }, { title: 'x'.repeat(49), description: '' }, { title: '', description: 'x'.repeat(256) }]) {
    assert.throws(() => validatePayload(invalid), /payload is invalid/);
  }
});

test('Menu tolerates stale controls and tears down idempotently', () => {
  const { adapter, fields, form } = fixture(); adapter.subscribe(() => {});
  fields.title.isConnected = false; assert.throws(() => adapter.capture(), /no longer current/);
  fields.title.isConnected = true; form.isConnected = false; assert.throws(() => adapter.capture(), /no longer current/);
  adapter.destroy(); adapter.destroy();
});
