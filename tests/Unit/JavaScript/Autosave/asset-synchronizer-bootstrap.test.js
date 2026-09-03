/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import AssetSynchronizer from '../../../../media_source/system/js/asset-synchronizer.es6.js';

const makeScript = ({
  type = '',
  src = '',
  name = null,
  content = '',
  classes = [],
} = {}) => {
  const element = {
    type,
    src,
    textContent: content,
    listeners: {},
    attributes: [],
    classList: {
      contains: (candidate) => classes.includes(candidate),
    },
    setAttribute(attribute, value) {
      const existing = this.attributes.find(({ name: current }) => current === attribute);

      if (existing) {
        existing.value = value;
      } else {
        this.attributes.push({ name: attribute, value });
      }

      if (attribute === 'type' || attribute === 'src') {
        this[attribute] = value;
      }
    },
    addEventListener(eventType, callback) {
      this.listeners[eventType] = callback;
    },
    hasAttribute(attribute) {
      return this.getAttribute(attribute) !== null;
    },
    getAttribute(attribute) {
      if (attribute === 'type' || attribute === 'src') {
        return this[attribute] || null;
      }

      const match = this.attributes.find(({ name: current }) => current === attribute);

      return match ? match.value : null;
    },
  };

  if (name) {
    element.attributes.push({ name: 'data-asset-name', value: name });
  }

  return element;
};

const makeDocument = (scripts) => {
  const appended = [];
  const documentSource = {
    baseURI: 'http://localhost/administrator/index.php',
    querySelectorAll: (selector) => (selector === 'script' ? scripts : []),
    createElement: () => makeScript(),
    head: {
      appendChild: (script) => {
        appended.push(script);
        scripts.push(script);
        queueMicrotask(() => script.listeners?.load?.());

        return script;
      },
    },
  };

  return { appended, document: documentSource };
};

const withLiveGlobals = async (liveDocument, callback) => {
  const previousWindow = globalThis.window;
  const previousDocument = globalThis.document;

  globalThis.window = { document: liveDocument };
  globalThis.document = liveDocument;

  try {
    return await callback();
  } finally {
    globalThis.window = previousWindow;
    globalThis.document = previousDocument;
  }
};

const snapshotOf = (documentSource) => ({
  document: documentSource,
  assets: AssetSynchronizer.collect(documentSource),
});

test('a response that repeats the live module assets is satisfied without loading', async () => {
  const scripts = [
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
    makeScript({
      type: 'importmap',
      content: JSON.stringify({ imports: { 'system.alpha': '/media/system/js/alpha.min.js?v=1' } }),
    }),
  ];
  const live = makeDocument(scripts);

  await withLiveGlobals(live.document, async () => {
    const satisfied = await AssetSynchronizer.synchronize(snapshotOf(live.document));

    assert.equal(satisfied, true);
    assert.equal(live.appended.length, 0);
  });
});

test('a missing module whose imports are already mapped is loaded exactly once', async () => {
  const importmap = makeScript({
    type: 'importmap',
    content: JSON.stringify({ imports: { 'system.alpha': '/media/system/js/alpha.min.js?v=1' } }),
  });
  const live = makeDocument([
    importmap,
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
  ]);
  const response = makeDocument([
    makeScript({
      type: 'importmap',
      content: JSON.stringify({ imports: { 'system.alpha': '/media/system/js/alpha.min.js?v=1' } }),
    }),
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
    makeScript({ type: 'module', src: '/media/system/js/beta.min.js', name: 'system.beta' }),
  ]);

  await withLiveGlobals(live.document, async () => {
    const first = await AssetSynchronizer.synchronize(snapshotOf(response.document));

    assert.equal(first, true);
    assert.equal(live.appended.length, 1);
    assert.equal(live.appended[0].src, '/media/system/js/beta.min.js');

    const second = await AssetSynchronizer.synchronize(snapshotOf(response.document));

    assert.equal(second, true);
    assert.equal(live.appended.length, 1);
  });
});

test('a missing module requiring absent import map entries signals navigation and loads nothing', async () => {
  const live = makeDocument([
    makeScript({
      type: 'importmap',
      content: JSON.stringify({ imports: { 'system.alpha': '/media/system/js/alpha.min.js?v=1' } }),
    }),
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
  ]);
  const response = makeDocument([
    makeScript({
      type: 'importmap',
      content: JSON.stringify({
        imports: {
          'system.alpha': '/media/system/js/alpha.min.js?v=1',
          'com_autosave.integration-controller': '/media/com_autosave/js/integration-controller.min.js?v=1',
          'editor-api': '/media/system/js/editors/editor-api.min.js?v=1',
        },
      }),
    }),
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
    makeScript({
      type: 'module',
      src: '/media/com_categories/js/category-autosave.min.js',
      name: 'com_categories.category-autosave',
    }),
  ]);

  await withLiveGlobals(live.document, async () => {
    const satisfied = await AssetSynchronizer.synchronize(snapshotOf(response.document));

    assert.equal(satisfied, false);
    assert.equal(live.appended.length, 0);
  });
});

test('absent import map entries without new module scripts keep the partial synchronization', async () => {
  const live = makeDocument([
    makeScript({
      type: 'importmap',
      content: JSON.stringify({ imports: { 'system.alpha': '/media/system/js/alpha.min.js?v=1' } }),
    }),
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
  ]);
  const response = makeDocument([
    makeScript({
      type: 'importmap',
      content: JSON.stringify({
        imports: {
          'system.alpha': '/media/system/js/alpha.min.js?v=1',
          'system.extra': '/media/system/js/extra.min.js?v=1',
        },
      }),
    }),
    makeScript({ type: 'module', src: '/media/system/js/alpha.min.js', name: 'system.alpha' }),
  ]);

  await withLiveGlobals(live.document, async () => {
    const satisfied = await AssetSynchronizer.synchronize(snapshotOf(response.document));

    assert.equal(satisfied, true);
    assert.equal(live.appended.length, 0);
  });
});
