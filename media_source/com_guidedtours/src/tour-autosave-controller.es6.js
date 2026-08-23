/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import AutosaveIntegrationController, {
  defaultOptionsReader,
  isPlainObject,
  resolveAutosaveUiMount,
} from 'com_autosave.integration-controller';
import { JoomlaEditor } from 'editor-api';
import TourAutosaveAdapter, { normalizeCanonicalId } from './tour-autosave-adapter.es6.js';

const TOUR_OPTIONS_KEY = 'com_guidedtours.autosave.tour';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_KEYS = Object.freeze(['title', 'uid', 'description', 'note', 'url', 'autostart']);
const TOUR_CANONICAL_TASK_POLICY = Object.freeze({
  'tour.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'tour.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'tour.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'tour.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'tour.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validateTourConfiguration = (configuration) => {
  if (!isPlainObject(configuration) || configuration.enabled !== true) return null;
  if (configuration.context !== 'com_guidedtours.tour'
    || !Number.isInteger(configuration.payloadSchemaVersion) || configuration.payloadSchemaVersion <= 0
    || typeof configuration.formId !== 'string' || !configuration.formId
    || !isPlainObject(configuration.fieldIds)
    || Object.keys(configuration.fieldIds).length !== FIELD_KEYS.length
    || !FIELD_KEYS.every((key) => typeof configuration.fieldIds[key] === 'string' && configuration.fieldIds[key])) {
    throw new TypeError('The Guided Tour Autosave page configuration is invalid.');
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

export default class TourAutosaveController extends AutosaveIntegrationController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    editorRegistry = JoomlaEditor,
    adapterFactory = (options) => new TourAutosaveAdapter(options),
    ...integrationOptions
  } = {}) {
    if (!documentSource || typeof documentSource.getElementById !== 'function'
      || typeof documentSource.querySelectorAll !== 'function'
      || !editorRegistry || typeof editorRegistry.get !== 'function'
      || typeof editorRegistry.subscribeLifecycle !== 'function'
      || typeof adapterFactory !== 'function') {
      throw new TypeError('The Guided Tour Autosave controller configuration is invalid.');
    }

    const resolveTour = () => {
      const tour = validateTourConfiguration(optionsReader(TOUR_OPTIONS_KEY, null));
      if (!tour) return null;
      const form = documentSource.getElementById(tour.formId);
      if (!form?.isConnected) return null;

      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [
        key,
        key === 'autostart'
          ? [...documentSource.querySelectorAll('[name="jform[autostart]"]')]
          : documentSource.getElementById(tour.fieldIds[key]),
      ]));
      if (!FIELD_KEYS.every((key) => (key === 'autostart'
        ? fields[key].length > 0 && fields[key].every((field) => field.isConnected && form.contains(field))
        : fields[key]?.isConnected && form.contains(fields[key])))) return null;

      const editor = editorRegistry.get(tour.fieldIds.description);
      if (!editor || typeof editor.supportsChangeObservation !== 'function'
        || editor.supportsChangeObservation() !== true
        || typeof editor.subscribeChange !== 'function'
        || typeof editor.getValue !== 'function' || typeof editor.setValue !== 'function') return null;

      return {
        descriptor: { context: tour.context, targetId: tour.targetId, payloadSchemaVersion: tour.payloadSchemaVersion },
        form,
        identityParts: [editor, tour.fieldIds.description, ...FIELD_KEYS.flatMap((key) => fields[key])],
        taskPolicy: TOUR_CANONICAL_TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: { locale: tour.locale, timeZone: tour.timeZone },
        pairProperties: { fields, editor },
      };
    };

    super({
      ...integrationOptions,
      documentSource,
      optionsReader,
      integrationResolver: resolveTour,
      adapterFactory: (resolution) => {
        const adapter = adapterFactory({
          descriptor: resolution.descriptor,
          form: resolution.form,
          fields: resolution.pairProperties.fields,
          editor: resolution.pairProperties.editor,
          getCurrentEditor: (id) => editorRegistry.get(id),
        });
        adapter.initializeBaseline();
        return adapter;
      },
      lifecycleSubscriber: (reconcile) => editorRegistry.subscribeLifecycle((detail) => {
        const configuration = optionsReader(TOUR_OPTIONS_KEY, null);
        if (detail?.id === configuration?.fieldIds?.description) reconcile();
      }),
    });
  }
}

export {
  FIELD_KEYS,
  RUNTIME_OPTIONS_KEY,
  TOUR_CANONICAL_TASK_POLICY,
  TOUR_OPTIONS_KEY,
  validateTourConfiguration,
};
