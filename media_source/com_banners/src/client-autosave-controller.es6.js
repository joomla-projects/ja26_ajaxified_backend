/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, {
  defaultOptionsReader,
  isPlainObject,
  resolveAutosaveUiMount,
} from 'com_autosave.integration-controller';
import ClientAutosaveAdapter, { normalizeCanonicalId } from './client-autosave-adapter.es6.js';

const CLIENT_OPTIONS_KEY = 'com_banners.autosave.client';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_KEYS = Object.freeze([
  'name', 'contact', 'email', 'extrainfo', 'metakey', 'metakey_prefix', 'version_note',
  'purchase_type', 'track_impressions', 'track_clicks', 'own_prefix',
]);
const CLIENT_CANONICAL_TASK_POLICY = Object.freeze({
  'client.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'client.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'client.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'client.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'client.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validateClientConfiguration = (configuration) => {
  if (!isPlainObject(configuration) || configuration.enabled !== true) return null;
  if (typeof configuration.context !== 'string' || !configuration.context
    || !Number.isInteger(configuration.payloadSchemaVersion) || configuration.payloadSchemaVersion <= 0
    || typeof configuration.formId !== 'string' || !configuration.formId
    || !isPlainObject(configuration.fieldIds)
    || !FIELD_KEYS.every((key) => typeof configuration.fieldIds[key] === 'string' && configuration.fieldIds[key])) {
    throw new TypeError('The Banner Client Autosave page configuration is invalid.');
  }

  return Object.freeze({
    context: configuration.context,
    targetId: normalizeCanonicalId(configuration.targetId),
    payloadSchemaVersion: configuration.payloadSchemaVersion,
    formId: configuration.formId,
    fieldIds: Object.freeze({ ...configuration.fieldIds }),
    locale: typeof configuration.locale === 'string' ? configuration.locale : '',
    timeZone: typeof configuration.timeZone === 'string' ? configuration.timeZone : '',
  });
};

export default class ClientAutosaveController extends AutosaveIntegrationController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    adapterFactory = (options) => new ClientAutosaveAdapter(options),
    ...integrationOptions
  } = {}) {
    if (!documentSource
      || typeof documentSource.getElementById !== 'function'
      || typeof documentSource.querySelectorAll !== 'function'
      || typeof adapterFactory !== 'function') {
      throw new TypeError('The Banner Client Autosave controller configuration is invalid.');
    }

    const resolveClient = () => {
      const client = validateClientConfiguration(optionsReader(CLIENT_OPTIONS_KEY, null));
      if (!client) return null;
      const form = documentSource.getElementById(client.formId);
      if (!form?.isConnected) return null;
      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [
        key,
        key === 'own_prefix'
          ? [...documentSource.querySelectorAll(`[name="jform[${key}]"]`)]
          : documentSource.getElementById(client.fieldIds[key]),
      ]));
      if (!FIELD_KEYS.every((key) => (key === 'own_prefix'
        ? fields[key].length > 0 && fields[key].every((field) => field.isConnected && form.contains(field))
        : fields[key]?.isConnected && form.contains(fields[key])))) return null;

      return {
        descriptor: { context: client.context, targetId: client.targetId, payloadSchemaVersion: client.payloadSchemaVersion },
        form,
        identityParts: FIELD_KEYS.flatMap((key) => (Array.isArray(fields[key]) ? fields[key] : [fields[key]])),
        taskPolicy: CLIENT_CANONICAL_TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: { locale: client.locale, timeZone: client.timeZone },
        pairProperties: { fields },
      };
    };

    super({
      ...integrationOptions,
      documentSource,
      optionsReader,
      integrationResolver: resolveClient,
      adapterFactory: (resolution) => {
        const adapter = adapterFactory({ descriptor: resolution.descriptor, form: resolution.form, fields: resolution.pairProperties.fields });
        adapter.initializeBaseline();
        return adapter;
      },
    });
  }
}

export { CLIENT_CANONICAL_TASK_POLICY, CLIENT_OPTIONS_KEY, FIELD_KEYS, RUNTIME_OPTIONS_KEY, validateClientConfiguration };
