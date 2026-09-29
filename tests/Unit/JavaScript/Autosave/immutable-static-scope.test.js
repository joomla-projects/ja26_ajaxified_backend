import assert from 'node:assert/strict';
import test from 'node:test';

import { AutosaveApiClient, AutosaveApiError } from '../../../../media_source/com_autosave/src/api-client.es6.js';
import CategoryAutosaveAdapter from '../../../../media_source/com_categories/src/category-autosave-adapter.es6.js';
import { validateConfiguration as validateCategoryConfiguration, FIELD_KEYS as CATEGORY_FIELD_KEYS } from '../../../../media_source/com_categories/src/category-autosave-controller.es6.js';

const endpoints = {
  initialize: 'i', initializeCreate: 'ic', preserve: 'p', detect: 'd', read: 'r',
  discard: 'x', prepareCanonicalAction: 'c', getCanonicalActionOutcome: 'o',
};

const apiFixture = () => {
  let captured = null;
  const client = new AutosaveApiClient({
    endpoints,
    csrf: 'token',
    fetchImpl: async (url, options) => {
      captured = { url, body: JSON.parse(options.body), headers: options.headers };
      return new Response(JSON.stringify({
        success: true,
        data: {
          continuation_id: 'a'.repeat(64),
          generation_id: 'b'.repeat(64),
          context: 'com_categories.category',
          target_id: `p1:${'c'.repeat(64)}`,
          base_revision: 'revision',
          payload_schema_version: 1,
        },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    },
  });
  return { client, captured: () => captured };
};

test('initializeCreate transports the candidate creation scope exactly once', async () => {
  const { client, captured } = apiFixture();
  await client.initializeCreate({ context: 'com_categories.category', initialization_key: 'form-1', create_scope: 'com_content' });
  assert.deepEqual(captured().body, {
    context: 'com_categories.category', initialization_key: 'form-1', create_scope: 'com_content',
  });
});

test('scope-free initializeCreate keeps the existing two-key request contract', async () => {
  const { client, captured } = apiFixture();
  await client.initializeCreate({ context: 'com_example.record', initialization_key: 'form-1' });
  assert.deepEqual(Object.keys(captured().body).sort(), ['context', 'initialization_key']);
});

test('initializeCreate rejects a malformed candidate scope before transport', async () => {
  const { client } = apiFixture();
  for (const create_scope of ['', 'com_content\nx', 'x'.repeat(256), 42]) {
    await assert.rejects(
      () => client.initializeCreate({ context: 'com_categories.category', initialization_key: 'form-1', create_scope }),
      (error) => error instanceof AutosaveApiError && error.code === 'invalid_request_payload',
    );
  }
});

const categoryFieldIds = Object.fromEntries(CATEGORY_FIELD_KEYS.map((key) => [key, `jform_${key}`]));

test('Category configuration accepts a bounded create scope in create mode', () => {
  const configuration = {
    enabled: true,
    context: 'com_categories.category',
    mode: 'create',
    targetId: null,
    payloadSchemaVersion: 1,
    formId: 'item-form',
    fieldIds: categoryFieldIds,
    createScope: 'com_content',
  };

  const validated = validateCategoryConfiguration(configuration);
  assert.equal(validated.mode, 'create');
  assert.equal(validated.targetId, null);
  assert.equal(validated.createScope, 'com_content');

  assert.throws(() => validateCategoryConfiguration({ ...configuration, createScope: 'com_content\nx' }), TypeError);
  assert.throws(() => validateCategoryConfiguration({ ...configuration, createScope: '' }), TypeError);
  assert.throws(() => validateCategoryConfiguration({ ...configuration, targetId: '7' }), TypeError);
});

test('Category adapter accepts null, provisional and canonical targets', () => {
  const form = { isConnected: true, contains: () => true };
  const fields = Object.fromEntries(CATEGORY_FIELD_KEYS.map((key) => [key, { value: '', isConnected: true }]));
  const editor = { getValue: () => '', setValue() {}, subscribeChange: () => () => {} };
  const options = (targetId) => ({
    descriptor: { context: 'com_categories.category', targetId, payloadSchemaVersion: 1 },
    form, fields, editor, getCurrentEditor: () => editor,
  });

  assert.equal(new CategoryAutosaveAdapter(options(null)).getDescriptor().targetId, null);
  assert.equal(new CategoryAutosaveAdapter(options(`p1:${'a'.repeat(64)}`)).getDescriptor().targetId, `p1:${'a'.repeat(64)}`);
  assert.equal(new CategoryAutosaveAdapter(options('7')).getDescriptor().targetId, '7');
  assert.throws(() => new CategoryAutosaveAdapter(options('0')), TypeError);
});
