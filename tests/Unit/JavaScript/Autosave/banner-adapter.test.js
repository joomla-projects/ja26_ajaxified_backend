/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import BannerAutosaveAdapter, { isStableMediaReference, validatePayload } from '../../../../media_source/com_banners/src/banner-autosave-adapter.es6.js';

class Control extends EventTarget {
  constructor(id, value = '', checked = false) {
    super(); this.id = id; this.value = value; this.checked = checked; this.isConnected = true; this.attributes = new Map();
  }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  setAttribute(name, value) { this.attributes.set(name, value); }
}

const payload = () => ({
  name: 'Banner', alias: 'banner', description: '<p>Draft</p>', custombannercode: '<div>Code</div>',
  clickurl: 'https://example.test', version_note: '', publish_up: '25 August 2026 10:00',
  publish_up_alt: '2026-08-25 10:00:00', publish_down: '', publish_down_alt: '',
  imageurl: 'images/banner.jpg#joomlaImage://local-images/banner.jpg', width: '800', height: '200',
  alt: 'Banner alt', metakey: '', metakey_prefix: '', type: 0, own_prefix: 1, catid: 2, cid: 3,
});

const fixture = () => {
  const source = payload();
  const fields = Object.fromEntries(Object.entries(source)
    .filter(([key]) => !key.endsWith('_alt') && key !== 'description' && key !== 'own_prefix')
    .map(([key, value]) => [key, new Control(`jform_${key}`, String(value))]));
  fields.description = new Control('jform_description');
  fields.own_prefix = [new Control('jform_own_prefix0', '0'), new Control('jform_own_prefix1', '1', true)];
  fields.publish_up.setAttribute('data-alt-value', source.publish_up_alt);
  fields.publish_down.setAttribute('data-alt-value', source.publish_down_alt);
  const controls = new Set(Object.values(fields).flat());
  const mediaField = {
    isConnected: true, contains: (control) => control === fields.imageurl,
    setValue: (value) => { fields.imageurl.value = value; fields.imageurl.dispatchEvent(new Event('change')); },
  };
  const form = { isConnected: true, contains: (control) => controls.has(control) || control === mediaField };
  let editorValue = source.description;
  let editorCallback;
  const editor = {
    getValue: () => editorValue,
    setValue: (value) => { editorValue = value; },
    subscribeChange: (callback) => { editorCallback = callback; return () => { editorCallback = null; }; },
  };
  const adapter = new BannerAutosaveAdapter({
    descriptor: { context: 'com_banners.banner', targetId: '42', payloadSchemaVersion: 2 },
    form, fields, editor, getCurrentEditor: () => editor, mediaField,
  });
  return {
    adapter,
    editorChange: (value) => { editorValue = value; editorCallback?.(); },
    fields,
    form,
  };
};

test('Banner adapter captures only the exact dates, stable-media, editor and scalar contract', () => {
  const { adapter } = fixture();
  assert.deepEqual(adapter.capture(), payload());
  assert.deepEqual(Object.keys(adapter.capture()), Object.keys(payload()));
});

test('Banner recovery restores editor, both calendar representations and media through widget API', () => {
  const { adapter, fields } = fixture();
  const restored = {
    ...payload(), description: '<p>Recovered</p>', publish_up: 'Tomorrow', publish_up_alt: '2026-08-26 00:00:00',
    imageurl: 'https://cdn.example.test/banner.jpg', type: 1, own_prefix: 0, catid: 4, cid: 5,
  };
  let dirty = 0;
  adapter.initializeBaseline().subscribe(() => { dirty += 1; });
  adapter.apply(restored);
  assert.deepEqual(adapter.capture(), restored);
  assert.equal(fields.publish_up.getAttribute('data-alt-value'), restored.publish_up_alt);
  assert.equal(fields.own_prefix[0].checked, true);
  assert.equal(dirty, 0);
});

test('Banner adapter observes ordinary, date, media and editor changes and tears down safely', () => {
  const { adapter, editorChange, fields } = fixture();
  let dirty = 0;
  adapter.initializeBaseline().subscribe(() => { dirty += 1; });
  fields.name.value = 'Changed'; fields.name.dispatchEvent(new Event('input'));
  fields.publish_down.value = 'Tomorrow'; fields.publish_down.dispatchEvent(new Event('change'));
  fields.imageurl.value = 'images/other.jpg'; fields.imageurl.dispatchEvent(new Event('change'));
  editorChange('<p>Changed</p>');
  assert.equal(dirty, 4);
  adapter.destroy(); adapter.destroy(); fields.name.dispatchEvent(new Event('input'));
  assert.equal(dirty, 4);
});

test('Banner payload rejects missing, extra, transient, credentialed and invalid values', () => {
  const missing = payload(); delete missing.alias;
  for (const invalid of [
    missing, { ...payload(), state: 1 }, { ...payload(), type: '0' }, { ...payload(), width: '-1' },
    { ...payload(), imageurl: 'blob:temporary' }, { ...payload(), imageurl: 'https://user@example.test/a' },
  ]) assert.throws(() => validatePayload(invalid), /payload is invalid/);
  assert.equal(isStableMediaReference('images/banner.jpg'), true);
  assert.equal(isStableMediaReference('data:image/png;base64,AA'), false);
});

test('Banner relation fields keep the category mandatory and the client optional', () => {
  assert.deepEqual(validatePayload({ ...payload(), cid: 0 }), { ...payload(), cid: 0 });
  assert.throws(() => validatePayload({ ...payload(), catid: 0 }), /payload is invalid/);
  assert.throws(() => validatePayload({ ...payload(), cid: -1 }), /payload is invalid/);
});

test('Banner adapter arms an incomplete new form and activates on the first valid change', () => {
  const { adapter, fields } = fixture();
  fields.catid.value = '';
  fields.cid.value = '';
  adapter.initializeBaseline();
  assert.equal(adapter.baseline, null);

  let dirty = 0;
  adapter.subscribe(() => { dirty += 1; });
  fields.catid.value = '3';
  fields.catid.dispatchEvent(new Event('change'));

  assert.equal(dirty, 1);
  assert.equal(adapter.baseline.catid, 3);
  assert.equal(adapter.baseline.cid, 0);
});
