import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import FilterAutosaveAdapter, { FIELD_KEYS, MAXIMUM_MAPS, validatePayload } from '../../../../media_source/com_finder/src/filter-autosave-adapter.es6.js';
import FilterAutosaveController, { OPTIONS_KEY, TASK_POLICY, validateConfiguration } from '../../../../media_source/com_finder/src/filter-autosave-controller.es6.js';

class Control extends EventTarget {
  constructor(id, value = '') { super(); this.id = id; this.value = value; this.checked = false; this.isConnected = true; this.attributes = new Map(); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  setAttribute(name, value) { this.attributes.set(name, value); }
}

const fixture = () => {
  const values = { title: 'Filter', alias: 'filter', created: 'Today', created_by: '7', created_by_alias: '', state: '1', w1: '-1', d1: 'Yesterday', w2: '', d2: '' };
  const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, new Control(`jform_${key}`, values[key])]));
  fields.created_by.id = 'jform_created_by_id';
  fields.created.setAttribute('data-alt-value', '2026-08-26 00:00:00'); fields.d1.setAttribute('data-alt-value', '2026-08-25 00:00:00');
  const nodes = ['2', '9', '15'].map((id) => new Control(`tax-${id}`, id)); nodes[0].checked = true; nodes[1].checked = true;
  const controls = new Set([...Object.values(fields), ...nodes]);
  const form = { id: 'adminForm', isConnected: true, contains: (value) => controls.has(value), querySelectorAll: () => nodes, querySelector: () => null };
  return { adapter: new FilterAutosaveAdapter({ descriptor: { context: 'com_finder.filter', targetId: '42', payloadSchemaVersion: 1 }, form, fields }), fields, form, nodes };
};

test('Finder Filter captures exact bounded structured state and excludes arbitrary form data', () => {
  const { adapter } = fixture(); const payload = adapter.capture();
  assert.deepEqual(payload.taxonomy_ids, ['2', '9']); assert.deepEqual(Object.keys(payload.params), ['w1', 'd1', 'd1_alt', 'w2', 'd2', 'd2_alt']);
  assert.equal(Object.hasOwn(payload, 'task'), false); assert.equal(Object.hasOwn(payload, 'filter_id'), false);
});

test('Finder Filter restores empty and multiple selections with normal events', () => {
  const { adapter, nodes } = fixture(); adapter.initializeBaseline(); const restored = adapter.capture(); restored.taxonomy_ids = ['15'];
  let changes = 0; nodes.forEach((node) => node.addEventListener('change', () => { changes += 1; })); adapter.apply(restored);
  assert.deepEqual(nodes.map((node) => node.checked), [false, false, true]); assert.equal(changes, 3);
  adapter.apply({ ...restored, taxonomy_ids: [] }); assert.deepEqual(nodes.map((node) => node.checked), [false, false, false]);
});

test('Finder Filter rejects arbitrary nesting, duplicates, invalid IDs and cardinality overflow', () => {
  const source = fixture().adapter.capture();
  for (const invalid of [{ ...source, params: { ...source.params, extra: [] } }, { ...source, taxonomy_ids: ['1', '1'] },
    { ...source, taxonomy_ids: ['01'] }, { ...source, taxonomy_ids: Array.from({ length: MAXIMUM_MAPS + 1 }, (_, index) => String(index + 1)) }]) {
    assert.throws(() => validatePayload(invalid), /payload is invalid/);
  }
  assert.throws(() => validatePayload({ ...source, title: 'x'.repeat(256) }), /payload is invalid/);
  assert.throws(() => validatePayload({ ...source, created_by: '2147483648' }), /payload is invalid/);
});

test('Finder Filter dirty detection and teardown cover scalar and taxonomy controls', () => {
  const { adapter, fields, nodes } = fixture(); let dirty = 0; adapter.initializeBaseline().subscribe(() => { dirty += 1; });
  fields.title.value = 'Changed'; fields.title.dispatchEvent(new Event('input')); nodes[2].checked = true; nodes[2].dispatchEvent(new Event('change'));
  assert.equal(dirty, 2); adapter.destroy(); nodes[2].dispatchEvent(new Event('change')); assert.equal(dirty, 2);
});

const fieldIds = Object.fromEntries(FIELD_KEYS.map((key) => [key, key === 'created_by' ? 'jform_created_by_id' : `jform_${key}`]));
test('Finder Filter configuration enforces existing identity and canonical tasks', () => {
  const config = { enabled: true, context: 'com_finder.filter', targetId: '42', payloadSchemaVersion: 1, formId: 'adminForm', fieldIds };
  assert.equal(validateConfiguration(config).targetId, '42'); assert.equal(validateConfiguration({ enabled: false }), null);
  assert.throws(() => validateConfiguration({ ...config, targetId: '0' }), /target/);
  assert.deepEqual(TASK_POLICY['filter.save2copy'], { intent: 'save-copy', transport: 'native', canonical: true }); assert.equal(TASK_POLICY['filter.cancel'].canonical, false);
});

test('real Finder production wiring selects the canonical user ID and activates once', async () => {
  const { form, fields } = fixture(); const documentSource = new EventTarget();
  const visibleUserName = new Control('jform_created_by', 'Administrator');
  documentSource.getElementById = (id) => (id === form.id ? form : Object.values(fields).find((field) => field.id === id) || (id === visibleUserName.id ? visibleUserName : null));
  let runtimes = 0; class Runtime { constructor(options) { this.options = options; } start() { this.unsubscribe = this.options.adapter.subscribe(() => {}); return this; } destroy() { this.unsubscribe?.(); this.options.adapter.destroy(); } }
  const optionsReader = (key, fallback) => (key === OPTIONS_KEY ? { enabled: true, context: 'com_finder.filter', targetId: '42', payloadSchemaVersion: 1, formId: 'adminForm', fieldIds }
    : key === 'com_autosave.runtime' ? { endpoints: { initialize: 'i', preserve: 'p', detect: 'd', read: 'r', discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o' } } : key === 'csrf.token' ? 'token' : fallback);
  const controller = new FilterAutosaveController({ documentSource, optionsReader, apiClientFactory: () => ({}), runtimeFactory: (options) => { runtimes += 1; return new Runtime(options); }, coordinatorFactory: () => ({ start() { return this; }, destroy() {} }), presenterFactory: () => ({ start() { return this; }, destroy() {} }) });
  controller.start(); controller.start(); await controller.reconcile(); assert.equal(runtimes, 1);
  const native = new FilterAutosaveController({ documentSource, optionsReader: () => null, runtimeFactory: () => { throw new Error('must not activate'); } }); native.start(); await native.reconcile();
  const entry = await readFile(new URL('../../../../media_source/com_finder/js/filter-autosave.es6.js', import.meta.url), 'utf8'); assert.match(entry, /controller\.start\(\)/);
  const view = await readFile(new URL('../../../../administrator/components/com_finder/src/View/Filter/HtmlView.php', import.meta.url), 'utf8');
  const manifest = JSON.parse(await readFile(new URL('../../../../media_source/com_finder/joomla.asset.json', import.meta.url), 'utf8'));
  assert.match(view, /\$name === 'created_by' \? \$field->id \. '_id'/);
  assert.match(view, /'com_finder\.filter-autosave'/);
  assert.equal(manifest.assets.some((asset) => asset.name === 'com_finder.filter-autosave' && asset.uri === 'com_finder/filter-autosave.min.js'), true);
  assert.equal(fields.created_by.value, '7');
  assert.equal(visibleUserName.value, 'Administrator');
});
