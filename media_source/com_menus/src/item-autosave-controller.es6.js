import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import ItemAutosaveAdapter, { STATIC_STRINGS, validateSchema } from './item-autosave-adapter.es6.js';

const OPTIONS_KEY = 'com_menus.autosave.item';
const TASK_POLICY = Object.freeze({ 'item.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }), 'item.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }), 'item.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }), 'item.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }), 'item.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }) });
const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true) return null;
  if (value.context !== 'com_menus.item' || value.payloadSchemaVersion !== 1 || value.formId !== 'item-form' || !isPlainObject(value.fieldIds)) throw new TypeError('Invalid Menu Item configuration.');
  const mode = value.mode === 'create' ? 'create' : 'existing';
  const resolvedTargetId = mode === 'create' ? null : (Number.isInteger(value.targetId) ? String(value.targetId) : value.targetId);
  if (mode === 'create') {
    if (value.targetId !== null) throw new TypeError('Invalid Menu Item create target mode.');
  } else if (!/^[1-9][0-9]{0,9}$/.test(resolvedTargetId)) {
    throw new TypeError('Invalid Menu Item target.');
  }
  if (value.createScope !== undefined
    && !(typeof value.createScope === 'string' && value.createScope.length > 0 && value.createScope.length <= 255 && !/[\x00-\x1F\x7F]/.test(value.createScope))) throw new TypeError('Invalid Menu Item creation scope.');
  return Object.freeze({ ...value, mode, targetId: resolvedTargetId, dynamicSchema: validateSchema(value.dynamicSchema) });
};
export default class ItemAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource = globalThis.document, optionsReader = defaultOptionsReader, adapterFactory = (options) => new ItemAutosaveAdapter(options), createBindingFactory = (options) => new AutosaveCreateBinding(options), ...options } = {}) {
    if (typeof createBindingFactory !== 'function') throw new TypeError('The Menu Item Autosave controller configuration is invalid.');
    let createBinding = null;
    const resolve = () => {
      const config = validateConfiguration(optionsReader(OPTIONS_KEY, null)); if (!config) { createBinding = null; return null; }
      if (config.mode === 'create' && !createBinding) createBinding = createBindingFactory({ context: config.context });
      else if (config.mode === 'existing') createBinding = null;
      const createMode = createBinding?.descriptor() || null;
      const form = documentSource.getElementById(config.formId); if (!form?.isConnected) return null;
      const staticFields = {}; [...STATIC_STRINGS, 'browserNav'].forEach((key) => { staticFields[key] = documentSource.getElementById(config.fieldIds[key]); });
      const dynamicFields = {}; config.dynamicSchema.fields.forEach((field) => { dynamicFields[field.path[1]] = [...form.querySelectorAll(`[name="jform[params][${field.path[1]}]"]`)]; if (!dynamicFields[field.path[1]].length) { const control = documentSource.getElementById(field.id); if (control) dynamicFields[field.path[1]] = [control]; } });
      const all = [...Object.values(staticFields), ...Object.values(dynamicFields).flat()]; if (all.some((control) => !control?.isConnected || !form.contains(control))) return null;
      return { descriptor: { context: config.context, targetId: createMode?.targetId || config.targetId, payloadSchemaVersion: 1 }, createMode, form, identityParts: [createMode?.formInstanceId || config.targetId, ...all], taskPolicy: TASK_POLICY, statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'), recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'), presentationConfiguration: { locale: config.locale || '', timeZone: config.timeZone || '', supportStatus: config.dynamicSchema.support.status }, pairProperties: { staticFields, dynamicFields, schema: config.dynamicSchema }, createScope: config.mode === 'create' ? config.createScope || null : undefined };
    };
    super({ ...options, documentSource, optionsReader, integrationResolver: resolve, adapterFactory: (resolution) => adapterFactory({ descriptor: resolution.descriptor, form: resolution.form, ...resolution.pairProperties }).initializeBaseline() });
  }
}
export { OPTIONS_KEY, TASK_POLICY, validateConfiguration };
