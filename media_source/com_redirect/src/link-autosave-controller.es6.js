/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, {
  defaultOptionsReader,
  isPlainObject,
  resolveAutosaveUiMount,
} from 'com_autosave.integration-controller';
import LinkAutosaveAdapter, { normalizeCanonicalId } from './link-autosave-adapter.es6.js';

const LINK_OPTIONS_KEY = 'com_redirect.autosave.link';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_KEYS = Object.freeze(['old_url', 'new_url', 'comment']);
const LINK_CANONICAL_TASK_POLICY = Object.freeze({
  'link.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'link.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'link.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'link.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validateLinkConfiguration = (configuration) => {
  if (!isPlainObject(configuration) || configuration.enabled !== true) {
    return null;
  }

  if (typeof configuration.context !== 'string'
    || configuration.context.length === 0
    || !Number.isInteger(configuration.payloadSchemaVersion)
    || configuration.payloadSchemaVersion <= 0
    || typeof configuration.formId !== 'string'
    || configuration.formId.length === 0
    || !isPlainObject(configuration.fieldIds)
    || !FIELD_KEYS.every(
      (key) => typeof configuration.fieldIds[key] === 'string'
        && configuration.fieldIds[key].length > 0,
    )) {
    throw new TypeError('The Redirect Autosave page configuration is invalid.');
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

/**
 * Own the thin Redirect integration around the generic Autosave lifecycle.
 */
export default class LinkAutosaveController extends AutosaveIntegrationController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    adapterFactory = (options) => new LinkAutosaveAdapter(options),
    ...integrationOptions
  } = {}) {
    if (!documentSource
      || typeof documentSource.getElementById !== 'function'
      || typeof adapterFactory !== 'function') {
      throw new TypeError('The Redirect Autosave controller configuration is invalid.');
    }

    const resolveLink = () => {
      const link = validateLinkConfiguration(optionsReader(LINK_OPTIONS_KEY, null));

      if (!link) {
        return null;
      }

      const form = documentSource.getElementById(link.formId);

      if (!form?.isConnected) {
        return null;
      }

      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [
        key,
        documentSource.getElementById(link.fieldIds[key]),
      ]));

      if (!FIELD_KEYS.every(
        (key) => fields[key]?.isConnected && form.contains(fields[key]),
      )) {
        return null;
      }

      return {
        descriptor: {
          context: link.context,
          targetId: link.targetId,
          payloadSchemaVersion: link.payloadSchemaVersion,
        },
        form,
        identityParts: FIELD_KEYS.map((key) => fields[key]),
        taskPolicy: LINK_CANONICAL_TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: {
          locale: link.locale,
          timeZone: link.timeZone,
        },
        pairProperties: { fields },
      };
    };

    super({
      ...integrationOptions,
      documentSource,
      optionsReader,
      integrationResolver: resolveLink,
      adapterFactory: (resolution) => {
        const adapter = adapterFactory({
          descriptor: resolution.descriptor,
          form: resolution.form,
          fields: resolution.pairProperties.fields,
        });
        adapter.initializeBaseline();

        return adapter;
      },
    });
  }
}

export {
  FIELD_KEYS,
  LINK_CANONICAL_TASK_POLICY,
  LINK_OPTIONS_KEY,
  RUNTIME_OPTIONS_KEY,
  validateLinkConfiguration,
};
