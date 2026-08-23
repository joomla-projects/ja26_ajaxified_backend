/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  OPERATIONS,
  resolveAutosaveUiMount,
  sameRuntimeResolution,
  validateIntegrationResolution,
  validateRuntimeConfiguration,
} from '../../../../media_source/com_autosave/js/integration-controller.es6.js';

const endpoints = Object.fromEntries(OPERATIONS.map((operation) => [
  operation,
  `index.php?task=autosave.${operation}`,
]));

const createResolution = () => {
  const form = { isConnected: true };
  const field = {};

  return {
    descriptor: {
      context: 'com_example.item',
      targetId: '42',
      payloadSchemaVersion: 1,
    },
    form,
    identityParts: [field],
    taskPolicy: {
      'item.save': { intent: 'save-exit', transport: 'native', canonical: true },
    },
    statusMount: null,
    recoveryMount: null,
    presentationConfiguration: { locale: 'en-GB', timeZone: 'UTC' },
    pairProperties: { fields: { value: field } },
    runtime: validateRuntimeConfiguration({ endpoints }, 'csrf'),
  };
};

test('generic runtime configuration copies every literal operation and CSRF token', () => {
  const configuration = validateRuntimeConfiguration({ endpoints }, 'csrf');

  assert.deepEqual(configuration, { endpoints, csrf: 'csrf' });
  assert.notStrictEqual(configuration.endpoints, endpoints);
  assert.equal(Object.isFrozen(configuration), true);
  assert.equal(Object.isFrozen(configuration.endpoints), true);

  assert.throws(
    () => validateRuntimeConfiguration({ endpoints: { ...endpoints, detect: '' } }, 'csrf'),
    /runtime configuration is invalid/,
  );
  assert.throws(
    () => validateRuntimeConfiguration({ endpoints }, ''),
    /runtime configuration is invalid/,
  );
});

test('generic integration resolution accepts opaque component identity but protects pair ownership', () => {
  const resolution = createResolution();

  assert.strictEqual(validateIntegrationResolution(resolution), resolution);
  assert.throws(
    () => validateIntegrationResolution({
      ...resolution,
      pairProperties: { runtime: {} },
    }),
    /pair properties are invalid/,
  );
  assert.throws(
    () => validateIntegrationResolution({
      ...resolution,
      descriptor: { ...resolution.descriptor, targetId: 42 },
    }),
    /resolution is invalid/,
  );
});

test('generic runtime identity includes form, descriptor, transport and component parts', () => {
  const resolution = createResolution();
  const pair = {
    form: resolution.form,
    context: resolution.descriptor.context,
    targetId: resolution.descriptor.targetId,
    payloadSchemaVersion: resolution.descriptor.payloadSchemaVersion,
    runtimeConfiguration: resolution.runtime,
    identityParts: [...resolution.identityParts],
  };

  assert.equal(sameRuntimeResolution(pair, resolution), true);
  assert.equal(sameRuntimeResolution(pair, {
    ...resolution,
    identityParts: [{}],
  }), false);
  assert.equal(sameRuntimeResolution(pair, {
    ...resolution,
    runtime: validateRuntimeConfiguration({
      endpoints: { ...endpoints, preserve: 'changed' },
    }, 'csrf'),
  }), false);
});

test('generic UI mount resolution requires one connected form-owned root', () => {
  const mount = { isConnected: true };
  const form = {
    contains: (candidate) => candidate === mount,
    querySelectorAll: () => [mount],
  };

  assert.strictEqual(resolveAutosaveUiMount(form, '[data-ui]'), mount);
  mount.isConnected = false;
  assert.equal(resolveAutosaveUiMount(form, '[data-ui]'), null);
  assert.equal(resolveAutosaveUiMount(null, '[data-ui]'), null);
});
