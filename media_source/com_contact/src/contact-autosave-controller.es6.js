/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import { JoomlaEditor } from 'editor-api';
import ContactAutosaveAdapter, { normalizeCanonicalId } from './contact-autosave-adapter.es6.js';

const OPTIONS_KEY = 'com_contact.autosave.contact';
const FIELD_KEYS = Object.freeze([
  'name', 'alias', 'version_note', 'misc', 'image', 'con_position', 'email_to', 'address',
  'suburb', 'state', 'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage',
  'sortname1', 'sortname2', 'sortname3', 'publish_up', 'publish_down', 'metakey', 'metadesc',
]);
const TASK_POLICY = Object.freeze({
  'contact.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'contact.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'contact.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'contact.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'contact.save2menu': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'contact.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true) return null;
  if (value.context !== 'com_contact.contact' || !Number.isInteger(value.payloadSchemaVersion)
    || value.payloadSchemaVersion <= 0 || typeof value.formId !== 'string' || !value.formId
    || !isPlainObject(value.fieldIds) || Object.keys(value.fieldIds).length !== FIELD_KEYS.length
    || !FIELD_KEYS.every((key) => typeof value.fieldIds[key] === 'string' && value.fieldIds[key])) {
    throw new TypeError('The Contact Autosave page configuration is invalid.');
  }
  return Object.freeze({ ...value, targetId: normalizeCanonicalId(value.targetId), fieldIds: Object.freeze({ ...value.fieldIds }) });
};

export default class ContactAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource = globalThis.document, optionsReader = defaultOptionsReader, editorRegistry = JoomlaEditor, adapterFactory = (options) => new ContactAutosaveAdapter(options), ...options } = {}) {
    const resolve = () => {
      const config = validateConfiguration(optionsReader(OPTIONS_KEY, null));
      if (!config) return null;
      const form = documentSource.getElementById(config.formId);
      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, documentSource.getElementById(config.fieldIds[key])]));
      if (!form?.isConnected || !FIELD_KEYS.every((key) => fields[key]?.isConnected && form.contains(fields[key]))) return null;
      const editor = editorRegistry.get(config.fieldIds.misc);
      const mediaField = fields.image.closest('joomla-field-media');
      if (!editor?.supportsChangeObservation?.() || typeof editor.subscribeChange !== 'function'
        || !mediaField?.isConnected || !form.contains(mediaField) || typeof mediaField.setValue !== 'function') return null;
      return {
        descriptor: { context: config.context, targetId: config.targetId, payloadSchemaVersion: config.payloadSchemaVersion },
        form, identityParts: [editor, mediaField, ...FIELD_KEYS.map((key) => fields[key])], taskPolicy: TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: { locale: config.locale || '', timeZone: config.timeZone || '' },
        pairProperties: { fields, editor, mediaField },
      };
    };
    super({
      ...options, documentSource, optionsReader, integrationResolver: resolve,
      adapterFactory: (resolution) => adapterFactory({ descriptor: resolution.descriptor, form: resolution.form,
        fields: resolution.pairProperties.fields, editor: resolution.pairProperties.editor,
        getCurrentEditor: (id) => editorRegistry.get(id), mediaField: resolution.pairProperties.mediaField }).initializeBaseline(),
      lifecycleSubscriber: (reconcile) => editorRegistry.subscribeLifecycle(() => reconcile()),
    });
  }
}

export { FIELD_KEYS, OPTIONS_KEY, TASK_POLICY, validateConfiguration };
