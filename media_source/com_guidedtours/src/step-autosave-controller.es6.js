/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import { JoomlaEditor } from 'editor-api';
import StepAutosaveAdapter, { normalizeCanonicalId } from './step-autosave-adapter.es6.js';

const STEP_OPTIONS_KEY = 'com_guidedtours.autosave.step';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_KEYS = Object.freeze(['position', 'target', 'title', 'description', 'type', 'url', 'interactive_type', 'note', 'required', 'requiredvalue']);
const STEP_CANONICAL_TASK_POLICY = Object.freeze({ 'step.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }), 'step.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }), 'step.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }), 'step.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }), 'step.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }) });

const validateStepConfiguration = (configuration) => {
  if (!isPlainObject(configuration) || configuration.enabled !== true) return null;
  if (configuration.context !== 'com_guidedtours.step' || !Number.isInteger(configuration.payloadSchemaVersion) || configuration.payloadSchemaVersion <= 0
    || typeof configuration.formId !== 'string' || !configuration.formId || !isPlainObject(configuration.fieldIds)
    || Object.keys(configuration.fieldIds).length !== FIELD_KEYS.length || !FIELD_KEYS.every((key) => typeof configuration.fieldIds[key] === 'string' && configuration.fieldIds[key])) throw new TypeError('The Guided Tour Step Autosave page configuration is invalid.');
  const mode = configuration.mode === 'create' ? 'create' : 'existing';
  const targetId = mode === 'create' ? null : normalizeCanonicalId(configuration.targetId);
  if ((mode === 'create' && configuration.targetId !== null) || (mode === 'existing' && configuration.targetId === null)) throw new TypeError('The Guided Tour Step Autosave target mode is invalid.');
  return Object.freeze({ context: configuration.context, mode, targetId, payloadSchemaVersion: configuration.payloadSchemaVersion, formId: configuration.formId, fieldIds: Object.freeze({ ...configuration.fieldIds }), locale: typeof configuration.locale === 'string' ? configuration.locale : '', timeZone: typeof configuration.timeZone === 'string' ? configuration.timeZone : '' });
};

export default class StepAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource = globalThis.document, optionsReader = defaultOptionsReader, editorRegistry = JoomlaEditor, adapterFactory = (options) => new StepAutosaveAdapter(options), createBindingFactory = (options) => new AutosaveCreateBinding(options), ...integrationOptions } = {}) {
    if (!documentSource || typeof documentSource.getElementById !== 'function' || typeof documentSource.querySelectorAll !== 'function'
      || !editorRegistry || typeof editorRegistry.get !== 'function' || typeof editorRegistry.subscribeLifecycle !== 'function' || typeof adapterFactory !== 'function' || typeof createBindingFactory !== 'function') throw new TypeError('The Guided Tour Step Autosave controller configuration is invalid.');
    let createBinding = null;
    const resolveStep = () => {
      const step = validateStepConfiguration(optionsReader(STEP_OPTIONS_KEY, null)); if (!step) { createBinding = null; return null; }
      if (step.mode === 'create' && !createBinding) createBinding = createBindingFactory({ context: step.context }); else if (step.mode === 'existing') createBinding = null;
      const createMode = createBinding?.descriptor() || null;
      const form = documentSource.getElementById(step.formId); if (!form?.isConnected) return null;
      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, key === 'required' ? [...documentSource.querySelectorAll('[name="jform[params][required]"]')] : documentSource.getElementById(step.fieldIds[key])]));
      if (!FIELD_KEYS.every((key) => (key === 'required' ? fields[key].length > 0 && fields[key].every((field) => field.isConnected && form.contains(field)) : fields[key]?.isConnected && form.contains(fields[key])))) return null;
      const editor = editorRegistry.get(step.fieldIds.description);
      if (!editor || editor.supportsChangeObservation?.() !== true || typeof editor.subscribeChange !== 'function' || typeof editor.getValue !== 'function' || typeof editor.setValue !== 'function') return null;
      return { descriptor: { context: step.context, targetId: createMode?.targetId || step.targetId, payloadSchemaVersion: step.payloadSchemaVersion }, createMode, form, identityParts: [createMode?.formInstanceId || step.targetId, editor, ...FIELD_KEYS.flatMap((key) => fields[key])], taskPolicy: STEP_CANONICAL_TASK_POLICY, statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'), recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'), presentationConfiguration: { locale: step.locale, timeZone: step.timeZone }, pairProperties: { fields, editor } };
    };
    super({ ...integrationOptions, documentSource, optionsReader, integrationResolver: resolveStep, adapterFactory: (resolution) => { const adapter = adapterFactory({ descriptor: resolution.descriptor, form: resolution.form, fields: resolution.pairProperties.fields, editor: resolution.pairProperties.editor, getCurrentEditor: (id) => editorRegistry.get(id) }); adapter.initializeBaseline(); return adapter; }, lifecycleSubscriber: (reconcile) => editorRegistry.subscribeLifecycle((detail) => { const configuration = optionsReader(STEP_OPTIONS_KEY, null); if (detail?.id === configuration?.fieldIds?.description) reconcile(); }) });
  }
}

export { FIELD_KEYS, RUNTIME_OPTIONS_KEY, STEP_CANONICAL_TASK_POLICY, STEP_OPTIONS_KEY, validateStepConfiguration };
