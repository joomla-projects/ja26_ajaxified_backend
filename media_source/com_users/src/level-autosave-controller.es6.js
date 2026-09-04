/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import Integration, {
  defaultOptionsReader,
  isPlainObject,
  resolveAutosaveUiMount,
} from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import Adapter, { normalizeCanonicalId } from './level-autosave-adapter.es6.js';

export const OPTIONS_KEY = 'com_users.autosave.level';
export const FIELD_KEYS = Object.freeze(['title', 'rules']);
const RULES_SELECTOR = 'input[name="jform[rules][]"]';
export const TASK_POLICY = Object.freeze({
  'level.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'level.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'level.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'level.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'level.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

export const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true) return null;
  if (value.context !== 'com_users.level' || !Number.isInteger(value.payloadSchemaVersion)
    || value.payloadSchemaVersion <= 0 || typeof value.formId !== 'string' || !value.formId
    || !isPlainObject(value.fieldIds) || Object.keys(value.fieldIds).length !== FIELD_KEYS.length
    || !FIELD_KEYS.every((key) => typeof value.fieldIds[key] === 'string' && value.fieldIds[key])) {
    throw new TypeError('The Access Level Autosave page configuration is invalid.');
  }
  const mode = value.mode === 'create' ? 'create' : 'existing';
  const targetId = mode === 'create' ? null : normalizeCanonicalId(value.targetId);
  if ((mode === 'create' && value.targetId !== null) || (mode === 'existing' && value.targetId === null)) {
    throw new TypeError('The Access Level Autosave target mode is invalid.');
  }
  return Object.freeze({
    context: value.context,
    mode,
    targetId,
    payloadSchemaVersion: value.payloadSchemaVersion,
    formId: value.formId,
    fieldIds: Object.freeze({ ...value.fieldIds }),
    locale: typeof value.locale === 'string' ? value.locale : '',
    timeZone: typeof value.timeZone === 'string' ? value.timeZone : '',
  });
};

export default class LevelAutosaveController extends Integration {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    adapterFactory = (options) => new Adapter(options),
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
      const title = form ? documentSource.getElementById(config.fieldIds.title) : null;
      const rules = form?.querySelectorAll
        ? Array.from(form.querySelectorAll(RULES_SELECTOR))
        : [];

      if (!form?.isConnected || !title?.isConnected || !form.contains(title)
        || rules.length === 0 || !rules.every((control) => control.isConnected && form.contains(control))) {
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
          title,
          ...rules,
        ],
        taskPolicy: TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: {
          locale: config.locale,
          timeZone: config.timeZone,
        },
        pairProperties: { fields: { title, rules } },
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
