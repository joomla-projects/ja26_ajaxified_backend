/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import MenuAutosaveAdapter, { normalizeCanonicalId } from './menu-autosave-adapter.es6.js';

const MENU_OPTIONS_KEY = 'com_menus.autosave.menu';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_KEYS = Object.freeze(['title', 'description']);
const MENU_CANONICAL_TASK_POLICY = Object.freeze({
  'menu.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'menu.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'menu.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'menu.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validateMenuConfiguration = (configuration) => {
  if (!isPlainObject(configuration) || configuration.enabled !== true) return null;
  if (configuration.context !== 'com_menus.menu' || !Number.isInteger(configuration.payloadSchemaVersion)
    || configuration.payloadSchemaVersion <= 0 || typeof configuration.formId !== 'string' || !configuration.formId
    || !isPlainObject(configuration.fieldIds) || Object.keys(configuration.fieldIds).length !== FIELD_KEYS.length
    || !FIELD_KEYS.every((key) => typeof configuration.fieldIds[key] === 'string' && configuration.fieldIds[key])) {
    throw new TypeError('The Menu Autosave page configuration is invalid.');
  }
  return Object.freeze({ context: configuration.context, targetId: normalizeCanonicalId(configuration.targetId), payloadSchemaVersion: configuration.payloadSchemaVersion, formId: configuration.formId, fieldIds: Object.freeze({ ...configuration.fieldIds }), locale: typeof configuration.locale === 'string' ? configuration.locale : '', timeZone: typeof configuration.timeZone === 'string' ? configuration.timeZone : '' });
};

export default class MenuAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource = globalThis.document, optionsReader = defaultOptionsReader, adapterFactory = (options) => new MenuAutosaveAdapter(options), ...integrationOptions } = {}) {
    if (!documentSource || typeof documentSource.getElementById !== 'function' || typeof adapterFactory !== 'function') throw new TypeError('The Menu Autosave controller configuration is invalid.');
    const resolveMenu = () => {
      const menu = validateMenuConfiguration(optionsReader(MENU_OPTIONS_KEY, null)); if (!menu) return null;
      const form = documentSource.getElementById(menu.formId); if (!form?.isConnected) return null;
      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [key, documentSource.getElementById(menu.fieldIds[key])]));
      if (!FIELD_KEYS.every((key) => fields[key]?.isConnected && form.contains(fields[key]))) return null;
      return { descriptor: { context: menu.context, targetId: menu.targetId, payloadSchemaVersion: menu.payloadSchemaVersion }, form, identityParts: FIELD_KEYS.map((key) => fields[key]), taskPolicy: MENU_CANONICAL_TASK_POLICY, statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'), recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'), presentationConfiguration: { locale: menu.locale, timeZone: menu.timeZone }, pairProperties: { fields } };
    };
    super({ ...integrationOptions, documentSource, optionsReader, integrationResolver: resolveMenu, adapterFactory: (resolution) => { const adapter = adapterFactory({ descriptor: resolution.descriptor, form: resolution.form, fields: resolution.pairProperties.fields }); adapter.initializeBaseline(); return adapter; } });
  }
}

export { FIELD_KEYS, MENU_CANONICAL_TASK_POLICY, MENU_OPTIONS_KEY, RUNTIME_OPTIONS_KEY, validateMenuConfiguration };
