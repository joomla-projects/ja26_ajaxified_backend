import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import FieldAutosaveAdapter, { STATIC_BOOLEANS, STATIC_STRINGS, validateSchema } from './field-autosave-adapter.es6.js';

const OPTIONS_KEY = 'com_fields.autosave.field';
const TASK_POLICY = Object.freeze({
  'field.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'field.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'field.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'field.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'field.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});
const targetId = (value) => { const id = Number.isSafeInteger(value) ? String(value) : value; if (typeof id !== 'string' || !/^[1-9][0-9]{0,9}$/.test(id) || Number(id) > 2147483647) throw new TypeError('Invalid Custom Field target.'); return id; };
const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true) return null;
  if (value.context !== 'com_fields.field' || !Number.isInteger(value.payloadSchemaVersion) || value.payloadSchemaVersion !== 1 || typeof value.formId !== 'string' || !isPlainObject(value.fieldIds)
    || Object.keys(value.fieldIds).length !== STATIC_STRINGS.length + STATIC_BOOLEANS.length) throw new TypeError('Invalid Custom Field Autosave configuration.');
  const mode = value.mode === 'create' ? 'create' : 'existing';
  const resolvedTargetId = mode === 'create' ? null : targetId(value.targetId);
  if ((mode === 'create' && value.targetId !== null) || (mode === 'existing' && value.targetId === null)) throw new TypeError('Invalid Custom Field Autosave target mode.');
  if (value.createScope !== undefined
    && !(typeof value.createScope === 'string' && value.createScope.length > 0 && value.createScope.length <= 255 && !/[\x00-\x1F\x7F]/.test(value.createScope))) throw new TypeError('Invalid Custom Field Autosave creation scope.');
  return Object.freeze({ ...value, mode, targetId: resolvedTargetId, fieldIds: Object.freeze({ ...value.fieldIds }), dynamicSchema: validateSchema(value.dynamicSchema) });
};
export default class FieldAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource = globalThis.document, optionsReader = defaultOptionsReader, adapterFactory = (options) => new FieldAutosaveAdapter(options), createBindingFactory = (options) => new AutosaveCreateBinding(options), ...options } = {}) {
    if (typeof createBindingFactory !== 'function') throw new TypeError('The Custom Field Autosave controller configuration is invalid.');
    let createBinding = null;
    const resolve = () => {
      const config = validateConfiguration(optionsReader(OPTIONS_KEY, null)); if (!config) { createBinding = null; return null; }
      if (config.mode === 'create' && !createBinding) createBinding = createBindingFactory({ context: config.context });
      else if (config.mode === 'existing') createBinding = null;
      const createMode = createBinding?.descriptor() || null;
      const form = documentSource.getElementById(config.formId); if (!form?.isConnected) return null;
      const staticFields = {};
      STATIC_STRINGS.forEach((key) => { staticFields[key] = documentSource.getElementById(config.fieldIds[key]); });
      STATIC_BOOLEANS.forEach((key) => { staticFields[key] = [...form.querySelectorAll(`[name="jform[${key}]"]`)]; });
      const dynamicFields = {};
      config.dynamicSchema.fields.forEach((field) => { dynamicFields[field.path[1]] = field.kind === 'rows' ? [...form.querySelectorAll(`[name^="jform[fieldparams][${field.path[1]}]"]`)] : [...form.querySelectorAll(`[name="jform[fieldparams][${field.path[1]}]"]`)]; if (!dynamicFields[field.path[1]].length) { const control = documentSource.getElementById(field.id); if (control) dynamicFields[field.path[1]] = [control]; } });
      const all = [...STATIC_STRINGS.map((key) => staticFields[key]), ...STATIC_BOOLEANS.flatMap((key) => staticFields[key]), ...Object.values(dynamicFields).flat()];
      if (all.some((control) => !control?.isConnected || !form.contains(control))) return null;
      return { descriptor: { context: config.context, targetId: createMode?.targetId || config.targetId, payloadSchemaVersion: config.payloadSchemaVersion }, createMode, form, identityParts: [createMode?.formInstanceId || config.targetId, ...all], taskPolicy: TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'), recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: { locale: config.locale || '', timeZone: config.timeZone || '', supportStatus: config.dynamicSchema.support.status }, pairProperties: { staticFields, dynamicFields, schema: config.dynamicSchema }, createScope: config.mode === 'create' ? config.createScope || null : undefined };
    };
    super({ ...options, documentSource, optionsReader, integrationResolver: resolve, adapterFactory: (resolution) => adapterFactory({ descriptor: resolution.descriptor, form: resolution.form, ...resolution.pairProperties }).initializeBaseline() });
  }
}
export { OPTIONS_KEY, TASK_POLICY, validateConfiguration };
