import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import FilterAutosaveAdapter, { FIELD_KEYS } from './filter-autosave-adapter.es6.js';

const OPTIONS_KEY = 'com_finder.autosave.filter';
const TASK_POLICY = Object.freeze({
  'filter.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'filter.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'filter.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'filter.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'filter.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});
const normalizeId = (value) => { const id = Number.isSafeInteger(value) ? String(value) : value; if (typeof id !== 'string' || !/^[1-9][0-9]{0,9}$/.test(id) || Number(id) > 2147483647) throw new TypeError('Invalid Finder Filter target.'); return id; };
const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true) return null;
  if (value.context !== 'com_finder.filter' || !Number.isInteger(value.payloadSchemaVersion) || value.payloadSchemaVersion < 1
    || typeof value.formId !== 'string' || !value.formId || !isPlainObject(value.fieldIds)
    || Object.keys(value.fieldIds).length !== FIELD_KEYS.length || !FIELD_KEYS.every((key) => typeof value.fieldIds[key] === 'string' && value.fieldIds[key])) throw new TypeError('Invalid Finder Filter Autosave configuration.');
  const mode = value.mode === 'create' ? 'create' : 'existing'; const targetId = mode === 'create' ? null : normalizeId(value.targetId);
  if ((mode === 'create' && value.targetId !== null) || (mode === 'existing' && value.targetId === null)) throw new TypeError('Invalid Finder Filter target mode.');
  return Object.freeze({ ...value, mode, targetId, fieldIds: Object.freeze({ ...value.fieldIds }) });
};

export default class FilterAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource = globalThis.document, optionsReader = defaultOptionsReader, adapterFactory = (options) => new FilterAutosaveAdapter(options), createBindingFactory = (options) => new AutosaveCreateBinding(options), ...options } = {}) {
    let createBinding = null;
    const resolve = () => { const config = validateConfiguration(optionsReader(OPTIONS_KEY, null)); if (!config) { createBinding = null; return null; }
      if (config.mode === 'create' && !createBinding) createBinding = createBindingFactory({ context: config.context }); else if (config.mode === 'existing') createBinding = null; const createMode = createBinding?.descriptor() || null;
      const form = documentSource.getElementById(config.formId); const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, documentSource.getElementById(config.fieldIds[key])]));
      if (!form?.isConnected || !FIELD_KEYS.every((key) => fields[key]?.isConnected && form.contains(fields[key]))) return null;
      return { descriptor: { context: config.context, targetId: createMode?.targetId || config.targetId, payloadSchemaVersion: config.payloadSchemaVersion }, createMode, form,
        identityParts: [createMode?.formInstanceId || config.targetId, ...FIELD_KEYS.map((key) => fields[key])], taskPolicy: TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'), recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: { locale: config.locale || '', timeZone: config.timeZone || '' }, pairProperties: { fields } };
    };
    super({ ...options, documentSource, optionsReader, integrationResolver: resolve,
      adapterFactory: (resolution) => adapterFactory({ descriptor: resolution.descriptor, form: resolution.form, fields: resolution.pairProperties.fields }).initializeBaseline() });
  }
}

export { OPTIONS_KEY, TASK_POLICY, validateConfiguration };
