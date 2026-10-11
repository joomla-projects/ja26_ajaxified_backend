/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import ClientAutosaveAdapter, { validatePayload } from '../../../../media_source/com_banners/src/client-autosave-adapter.es6.js';

class Control extends EventTarget {
  constructor(value, checked = false) { super(); this.value = value; this.checked = checked; this.isConnected = true; }
}

const payload = () => ({
  name: ' Client ', contact: '', email: 'invalid@', extrainfo: ' extra ', metakey: 'keys', metakey_prefix: '', version_note: 'note',
  purchase_type: 0, track_impressions: -1, track_clicks: 1, own_prefix: 0,
});
const fixture = () => {
  const source = payload();
  const fields = Object.fromEntries(Object.entries(source).map(([key, value]) => [key, new Control(String(value))]));
  fields.own_prefix = [new Control('0', true), new Control('1')];
  const controls = new Set(Object.values(fields).flat());
  const form = { isConnected: true, contains: (control) => controls.has(control) };
  const adapter = new ClientAutosaveAdapter({ descriptor: { context: 'com_banners.client', targetId: '42', payloadSchemaVersion: 1 }, form, fields });
  return { adapter, controls, fields, form };
};

test('Banner Client adapter captures the exact allow-list and preserves temporary invalid business values', () => {
  const { adapter } = fixture();
  assert.deepEqual(adapter.capture(), payload());
  assert.deepEqual(Object.keys(adapter.capture()), Object.keys(payload()));
});

test('Banner Client adapter emits metadata-free dirty signals for text, select, and radio controls', () => {
  const { adapter, fields } = fixture();
  const calls = [];
  adapter.initializeBaseline();
  adapter.subscribe((...args) => calls.push(args));
  fields.name.value = 'changed'; fields.name.dispatchEvent(new Event('input'));
  fields.purchase_type.value = '2'; fields.purchase_type.dispatchEvent(new Event('change'));
  fields.own_prefix[0].checked = false; fields.own_prefix[1].checked = true; fields.own_prefix[1].dispatchEvent(new Event('change'));
  assert.equal(calls.length, 3);
  calls.forEach((args) => assert.deepEqual(args, []));
});

test('Banner Client recovery validates atomically, applies radio values, and suppresses self notifications', () => {
  const { adapter, fields } = fixture();
  let dirty = 0;
  adapter.initializeBaseline(); adapter.subscribe(() => { dirty += 1; });
  assert.throws(() => adapter.apply({ ...payload(), state: 1 }), /payload is invalid/);
  assert.throws(() => adapter.apply({ ...payload(), track_clicks: 7 }), /payload is invalid/);
  const restored = { ...payload(), name: '', own_prefix: 1, purchase_type: 5 };
  adapter.apply(restored);
  assert.deepEqual(adapter.capture(), restored);
  assert.equal(fields.own_prefix[1].checked, true);
  assert.equal(dirty, 0);
});

test('Banner Client adapter rejects malformed controls and destroy is idempotent', () => {
  const { adapter, fields, form } = fixture();
  let dirty = 0;
  adapter.subscribe(() => { dirty += 1; });
  fields.purchase_type.value = '1x';
  assert.throws(() => adapter.capture(), /field is invalid/);
  fields.purchase_type.value = '1'; form.isConnected = false;
  assert.throws(() => adapter.capture(), /no longer current/);
  form.isConnected = true; adapter.destroy(); adapter.destroy();
  fields.name.dispatchEvent(new Event('input'));
  assert.equal(dirty, 0);
});

test('Banner Client payload rejects missing, extra, overlong, and non-integer fields', () => {
  assert.deepEqual(validatePayload(payload()), payload());
  const missing = payload(); delete missing.email;
  for (const invalid of [missing, { ...payload(), state: 1 }, { ...payload(), name: 'x'.repeat(256) }, { ...payload(), own_prefix: '1' }]) {
    assert.throws(() => validatePayload(invalid), /payload is invalid/);
  }
});
