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
import ArticleAutosaveAdapter, { normalizeCanonicalId } from './article-autosave-adapter.es6.js';
import ArticleAutosaveCreateBinding from './article-autosave-create-binding.es6.js';

const ARTICLE_OPTIONS_KEY = 'com_content.autosave.article';
const RUNTIME_OPTIONS_KEY = 'com_autosave.runtime';
const FIELD_KEYS = Object.freeze(['title', 'alias', 'articletext', 'catid']);
const ARTICLE_CANONICAL_TASK_POLICY = Object.freeze({
  'article.apply': Object.freeze({ intent: 'apply', transport: 'ajax', canonical: true }),
  'article.save': Object.freeze({ intent: 'save-exit', transport: 'native', canonical: true }),
  'article.save2new': Object.freeze({ intent: 'save-new', transport: 'native', canonical: true }),
  'article.save2copy': Object.freeze({ intent: 'save-copy', transport: 'native', canonical: true }),
  'article.cancel': Object.freeze({ intent: 'cancel', transport: 'native', canonical: false }),
});

const validateArticleConfiguration = (configuration) => {
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
    throw new TypeError('The Article Autosave page configuration is invalid.');
  }

  const mode = configuration.mode === 'create' ? 'create' : 'existing';
  const targetId = mode === 'create'
    ? null
    : normalizeCanonicalId(configuration.targetId, 'target');

  if ((mode === 'create' && configuration.targetId !== null)
    || (mode === 'existing' && configuration.targetId === null)) {
    throw new TypeError('The Article Autosave target mode is invalid.');
  }

  return Object.freeze({
    context: configuration.context,
    mode,
    targetId,
    payloadSchemaVersion: configuration.payloadSchemaVersion,
    formId: configuration.formId,
    fieldIds: Object.freeze({ ...configuration.fieldIds }),
    locale: typeof configuration.locale === 'string' ? configuration.locale : '',
    timeZone: typeof configuration.timeZone === 'string' ? configuration.timeZone : '',
  });
};

/**
 * Own one Article adapter/runtime pair for the current administrator document.
 */
export default class ArticleAutosaveController extends AutosaveIntegrationController {
  constructor({
    documentSource = globalThis.document,
    optionsReader = defaultOptionsReader,
    editorRegistry = JoomlaEditor,
    adapterFactory = (options) => new ArticleAutosaveAdapter(options),
    createBindingFactory = (options) => new ArticleAutosaveCreateBinding(options),
    ...integrationOptions
  } = {}) {
    if (!documentSource
      || typeof documentSource.getElementById !== 'function'
      || !editorRegistry
      || typeof editorRegistry.get !== 'function'
      || typeof editorRegistry.subscribeLifecycle !== 'function'
      || typeof adapterFactory !== 'function'
      || typeof createBindingFactory !== 'function') {
      throw new TypeError('The Article Autosave controller configuration is invalid.');
    }

    let createBinding = null;
    const resolveArticle = () => {
      const article = validateArticleConfiguration(
        optionsReader(ARTICLE_OPTIONS_KEY, null),
      );

      if (!article) {
        createBinding = null;

        return null;
      }

      if (article.mode === 'create' && !createBinding) {
        createBinding = createBindingFactory({ context: article.context });
      } else if (article.mode === 'existing' && createBinding) {
        createBinding = null;
      }

      const createMode = createBinding?.descriptor() || null;

      const form = documentSource.getElementById(article.formId);

      if (!form?.isConnected) {
        return null;
      }

      const fields = Object.fromEntries(FIELD_KEYS.map((key) => [
        key,
        documentSource.getElementById(article.fieldIds[key]),
      ]));

      if (!FIELD_KEYS.every(
        (key) => fields[key]?.isConnected && form.contains(fields[key]),
      )) {
        return null;
      }

      const editor = editorRegistry.get(article.fieldIds.articletext);

      if (!editor
        || typeof editor.supportsChangeObservation !== 'function'
        || editor.supportsChangeObservation() !== true
        || typeof editor.subscribeChange !== 'function'
        || typeof editor.getValue !== 'function'
        || typeof editor.setValue !== 'function') {
        return null;
      }

      return {
        descriptor: {
          context: article.context,
          targetId: createMode?.targetId || article.targetId,
          payloadSchemaVersion: article.payloadSchemaVersion,
        },
        createMode,
        form,
        identityParts: [
          editor,
          createMode?.formInstanceId || article.targetId,
          article.fieldIds.articletext,
          ...FIELD_KEYS.map((key) => fields[key]),
        ],
        taskPolicy: ARTICLE_CANONICAL_TASK_POLICY,
        statusMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-status-ui]'),
        recoveryMount: resolveAutosaveUiMount(form, '[data-joomla-autosave-recovery-ui]'),
        presentationConfiguration: {
          locale: article.locale,
          timeZone: article.timeZone,
        },
        pairProperties: {
          fields,
          editor,
          editorId: article.fieldIds.articletext,
        },
      };
    };

    super({
      ...integrationOptions,
      documentSource,
      optionsReader,
      integrationResolver: resolveArticle,
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
        const configuration = optionsReader(ARTICLE_OPTIONS_KEY, null);
        const editorId = configuration?.fieldIds?.articletext;

        if (detail?.id === editorId) {
          reconcile();
        }
      }),
    });
  }
}

export {
  ARTICLE_CANONICAL_TASK_POLICY,
  ARTICLE_OPTIONS_KEY,
  RUNTIME_OPTIONS_KEY,
  validateArticleConfiguration,
};
