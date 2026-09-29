/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, {
  defaultOptionsReader,
  isPlainObject,
  resolveAutosaveUiMount,
} from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import OverrideAutosaveAdapter, { KEYS } from './override-autosave-adapter.es6.js';

const OPTIONS_KEY = 'com_languages.autosave.override';
const TASK_POLICY = Object.freeze({
  'override.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'override.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'override.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'override.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validTarget = (target) => {
  if (typeof target !== 'string' || target.length > 191 || !target.startsWith('c1|')) return false;
  const parts = [];
  let offset = 3;

  while (offset < target.length) {
    const colon = target.indexOf(':', offset);

    if (colon < 0) return false;
    const lengthText = target.slice(offset, colon);

    if (!/^(?:0|[1-9][0-9]{0,2})$/.test(lengthText)) return false;
    const length = Number(lengthText);
    const start = colon + 1;
    const part = target.slice(start, start + length);

    if (part.length !== length) return false;
    parts.push(part);
    offset = start + length;

    if (offset < target.length) {
      if (target[offset] !== '|') return false;
      offset += 1;
    }
  }

  if (parts.length !== 4 || parts[0] !== 'languages.override'
    || !['site', 'administrator'].includes(parts[1])
    || !/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/.test(parts[2]) || parts[2].length > 32
    || !/^[A-Z0-9_.-]+$/.test(parts[3]) || parts[3].length > 110) return false;

  return `c1|${parts.map((part) => `${part.length}:${part}`).join('|')}` === target;
};

const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true) return null;
  if (value.context !== 'com_languages.override' || !Number.isInteger(value.payloadSchemaVersion)
    || value.payloadSchemaVersion <= 0 || typeof value.formId !== 'string' || !value.formId
    || !isPlainObject(value.fieldIds) || Object.keys(value.fieldIds).length !== KEYS.length
    || !KEYS.every((key) => typeof value.fieldIds[key] === 'string' && value.fieldIds[key])) {
    throw new TypeError('The Language Override Autosave page configuration is invalid.');
  }
  const mode = value.mode === 'create' ? 'create' : 'existing';
  const targetId = mode === 'create' ? null : value.targetId;

  if ((mode === 'create' && value.targetId !== null)
    || (mode === 'existing' && (!value.targetId || !validTarget(value.targetId)))) {
    throw new TypeError('The Language Override Autosave target mode is invalid.');
  }

  return Object.freeze({
    ...value,
    mode,
    targetId,
    fieldIds: Object.freeze({ ...value.fieldIds }),
  });
};

export default class OverrideAutosaveController extends AutosaveIntegrationController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    adapterFactory = (options) => new OverrideAutosaveAdapter(options),
    createBindingFactory = (options) => new AutosaveCreateBinding(options),
    ...options
  } = {}) {
    let createBinding = null;
    const resolve = () => {
      const config = validateConfiguration(optionsReader(OPTIONS_KEY, null));

      if (!config) {
        createBinding = null;

        return null;
      }

      if (config.mode === 'create' && !createBinding) {
        createBinding = createBindingFactory({ context: config.context });
      } else if (config.mode === 'existing' && createBinding) {
        createBinding = null;
      }

      const createMode = createBinding?.descriptor() || null;
      const form = documentSource.getElementById(config.formId);
      const fields = Object.fromEntries(KEYS.map((key) => [
        key,
        documentSource.getElementById(config.fieldIds[key]),
      ]));

      if (!form?.isConnected
        || !KEYS.every((key) => fields[key]?.isConnected && form.contains(fields[key]))) {
        return null;
      }

      return {
        descriptor: {
          context: config.context,
          targetId: createMode?.targetId || config.targetId,
          payloadSchemaVersion: config.payloadSchemaVersion,
        },
        createMode,
        form,
        identityParts: [
          createMode?.formInstanceId || config.targetId,
          ...KEYS.map((key) => fields[key]),
        ],
        taskPolicy: TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: {
          locale: config.locale || '',
          timeZone: config.timeZone || '',
        },
        pairProperties: { fields },
        createScope: config.mode === 'create' ? config.createScope || null : undefined,
      };
    };

    super({
      ...options,
      documentSource,
      optionsReader,
      integrationResolver: resolve,
      adapterFactory: (resolution) => adapterFactory({
        descriptor: resolution.descriptor,
        form: resolution.form,
        fields: resolution.pairProperties.fields,
      }).initializeBaseline(),
    });
  }
}

export { OPTIONS_KEY, TASK_POLICY, validTarget, validateConfiguration };
