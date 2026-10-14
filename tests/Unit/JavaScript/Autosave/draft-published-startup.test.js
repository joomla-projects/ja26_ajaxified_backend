/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import test from 'node:test';
import { createContext, Script } from 'node:vm';
import { transformSync } from '@babel/core';
import { fixture } from './draft-fixture.mjs';

// Execute the shipped code, retaining its embedded binder. Only external editor/dialog APIs are doubled.
const compile = (source) => new Script(transformSync(source, {
  babelrc: false,
  configFile: false,
  plugins: ['@babel/plugin-transform-modules-commonjs'],
}).code);
const runtimeScript = compile(await readFile('media/com_autosave/js/drafts.js', 'utf8'));
const components = (await readdir('media_source')).filter((name) => name.startsWith('com_'));
const entries = (await Promise.all(components.map(async (component) => {
  const files = await readdir(`media_source/${component}/js`).catch(() => []);
  return files.filter((name) => name.endsWith('-autosave.es6.js'))
    .map((name) => `media/${component}/js/${name.replace('.es6.js', '.js')}`);
}))).flat();

for (const entry of entries) {
  for (const suffix of ['', '.min']) {
    const published = entry.replace(/\.js$/, `${suffix}.js`);
    const script = compile(await readFile(published, 'utf8'));
    for (const outcome of ['ready', 'offline']) {
      test(`${published}: detection settles ${outcome} with the current layout`, async (t) => {
        const view = fixture();
        view.configuration.excluded = outcome === 'ready' ? ['missing_declared_field'] : [];
        let reloads = 0;
        view.window.location = { reload: () => {
          reloads += 1;
        } };
        const requests = [];
        const context = createContext({
          document: view.document,
          window: view.window,
          Joomla: { getOptions: (key) => (key === 'csrf.token' ? 'native-token' : view.configuration) },
          fetch: async (url, options) => {
            requests.push({ url, options });
            if (outcome === 'offline') throw new TypeError('Offline');
            return { ok: true, json: async () => ({ success: true, data: { candidates: [], conflict: false } }) };
          },
          AbortController,
          URL,
          DOMException,
          setTimeout,
          clearTimeout,
          exports: {},
        });
        runtimeScript.runInContext(context);
        const instances = [];
        const runtime = { ...context.exports, __esModule: true, default: class extends context.exports.default {
          constructor(options) {
            super(options);
            instances.push(this);
          }
        } };
        context.exports = {};
        context.require = (name) => ({
          'com_autosave.drafts': runtime,
          'editor-api': { JoomlaEditor: { subscribeLifecycle: () => () => {} } },
          'joomla.dialog': { __esModule: true, default: {} },
        })[name];
        t.after(() => instances.forEach((instance) => instance.destroy()));
        script.runInContext(context);
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(requests.length, 1, 'Startup must reach draft.detect before reporting readiness.');
        assert.equal(new URL(requests[0].url).searchParams.get('task'), 'draft.detect');
        assert.equal(view.status.attributes['aria-busy'], 'false', 'Checking must finish.');
        const state = outcome === 'ready' ? 'clean' : 'offline';
        assert.equal(view.nodes.get('[data-draft-state]').find((node) => node.dataset.draftState === state).hidden, false);
        assert.equal(view.nodes.get('[data-draft-action="retry"]')[0].hidden, outcome !== 'offline');
        assert.equal(view.nodes.get('[data-draft-partial]')[0].hidden, outcome !== 'ready', 'Missing declared fields are disclosed within recovery.');
        const leaving = new Event('pagehide');
        Object.defineProperty(leaving, 'persisted', { value: outcome === 'ready' });
        view.window.dispatchEvent(leaving);
        assert.equal(instances[0].destroyed, true, 'Departure aborts and disposes the old writer.');
        const restored = new Event('pageshow');
        Object.defineProperty(restored, 'persisted', { value: outcome === 'ready' });
        view.window.dispatchEvent(restored);
        view.window.dispatchEvent(restored);
        assert.equal(reloads, outcome === 'ready' ? 1 : 0, 'A cached return obtains one fresh native form; ordinary navigation does not reload.');
        assert.equal(instances.length, 1, 'An expired cached writer is never remounted.');

        assert.equal(view.control.value, 'Canonical title');
      });
    }
  }
}
