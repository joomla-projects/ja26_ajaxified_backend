import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { validateStepConfiguration } from '../../../../media_source/com_guidedtours/src/step-autosave-controller.es6.js';
import { validateConfiguration as validateUserGroupConfiguration } from '../../../../media_source/com_users/src/group-autosave-controller.es6.js';
import { validateConfiguration as validateTransitionConfiguration } from '../../../../media_source/com_workflow/src/transition-autosave-controller.es6.js';
import { validateMenuConfiguration } from '../../../../media_source/com_menus/src/menu-autosave-controller.es6.js';
import { validateConfiguration as validateWorkflowConfiguration } from '../../../../media_source/com_workflow/src/workflow-autosave-controller.es6.js';
import { validateConfiguration as validateFieldGroupConfiguration } from '../../../../media_source/com_fields/src/group-autosave-controller.es6.js';
import { validateConfiguration as validateStageConfiguration } from '../../../../media_source/com_workflow/src/stage-autosave-controller.es6.js';

const read = (path) => readFileSync(new URL(`../../../../${path}`, import.meta.url), 'utf8');
const manifest = (component) => JSON.parse(read(`media_source/${component}/joomla.asset.json`));
const asset = (component, name) => manifest(component).assets.find((entry) => entry.name === name);
const adapterSource = (component, controller) => {
  const path = `media_source/${component}/src/${controller.replace('-controller', '-adapter')}.es6.js`;
  const source = read(path);
  const reExport = source.match(/from '\.\/([^']+)\.es6\.js'/);

  return reExport ? `${source}\n${read(`media_source/${component}/src/${reExport[1]}.es6.js`)}` : source;
};

const specs = [
  ['com_guidedtours.step', validateStepConfiguration, ['position', 'target', 'title', 'description', 'type', 'url', 'interactive_type', 'note', 'required', 'requiredvalue'], 'com_guidedtours', 'step-autosave-controller', 'com_guidedtours.step-autosave'],
  ['com_users.group', validateUserGroupConfiguration, ['title'], 'com_users', 'group-autosave-controller', 'com_users.group-autosave'],
  ['com_workflow.transition', validateTransitionConfiguration, ['title', 'description', 'from_stage_id', 'to_stage_id'], 'com_workflow', 'transition-autosave-controller', 'com_workflow.transition-autosave'],
  ['com_menus.menu', validateMenuConfiguration, ['title', 'description'], 'com_menus', 'menu-autosave-controller', 'com_menus.menu-autosave'],
  ['com_workflow.workflow', validateWorkflowConfiguration, ['title', 'description'], 'com_workflow', 'workflow-autosave-controller', 'com_workflow.workflow-autosave'],
  ['com_fields.group', validateFieldGroupConfiguration, ['title', 'note', 'description'], 'com_fields', 'group-autosave-controller', 'com_fields.group-autosave'],
  ['com_workflow.stage', validateStageConfiguration, ['title', 'description'], 'com_workflow', 'stage-autosave-controller', 'com_workflow.stage-autosave'],
];

for (const [context, validate, fields, component, controller, assetName] of specs) {
  test(`${context} accepts only an unresolved null target in create mode`, () => {
    const configuration = {
      enabled: true,
      context,
      mode: 'create',
      targetId: null,
      payloadSchemaVersion: 1,
      formId: 'item-form',
      fieldIds: Object.fromEntries(fields.map((field) => [field, `jform_${field}`])),
    };

    assert.equal(validate(configuration).targetId, null);
    assert.equal(validate(configuration).mode, 'create');
    assert.throws(() => validate({ ...configuration, targetId: '7' }), TypeError);
    assert.throws(() => validate({ ...configuration, mode: 'existing' }), TypeError);

    // An existing record keeps the canonical target and the existing mode untouched.
    const existing = validate({ ...configuration, mode: 'existing', targetId: '7' });
    assert.equal(existing.mode, 'existing');
    assert.equal(existing.targetId, '7');

    // A provisional identity is never accepted where a canonical target belongs.
    assert.throws(() => validate({ ...configuration, mode: 'existing', targetId: `p1:${'a'.repeat(64)}` }), TypeError);
  });

  test(`${context} reuses the shared create binding rather than a second runtime`, () => {
    const source = read(`media_source/${component}/src/${controller}.es6.js`);

    assert.match(source, /^import \w+, \{[^}]*\} from 'com_autosave\.integration-controller';$/m);
    assert.match(source, /^import AutosaveCreateBinding from 'com_autosave\.create-binding';$/m);
    assert.doesNotMatch(source, /from 'com_autosave\.runtime'/);
    assert.doesNotMatch(source, /CreateRuntime/);

    const dependencies = asset(component, assetName).dependencies;
    assert.ok(dependencies.includes('com_autosave.integration-controller'), `${assetName} depends on the shared integration controller.`);
    assert.ok(dependencies.includes('com_autosave.create-binding'), `${assetName} depends on the shared create binding.`);

    // The adapter accepts the server-issued provisional identity for its descriptor target
    // and still rejects anything that is neither that shape nor a canonical id. Pure
    // re-export adapters (e.g. com_workflow.stage -> workflow adapter) are followed.
    const adapter = adapterSource(component, controller);
    assert.ok(adapter.includes('p1:[a-f0-9]{64}'), `${context} accepts a server-issued provisional identity.`);
    assert.ok(adapter.includes('normalizeAutosaveTarget(descriptor.targetId)'), `${context} normalizes its descriptor target.`);
    assert.ok(adapter.includes('normalizeCanonicalId'), `${context} still canonicalizes non-provisional targets.`);
  });
}

test('deferred contexts are not wired for create mode', () => {
  // com_categories.category stays deferred: the native new-category page never anchors the
  // owning extension in server-side user state, so no static provider could verify the
  // extension of the form the browser opened (see AuthorityBoundNewRecordAutosaveRolloutTest).
  assert.ok(
    !asset('com_categories', 'com_categories.category-autosave').dependencies.includes('com_autosave.create-binding'),
    'com_categories.category-autosave is deferred.',
  );
  assert.doesNotMatch(read('media_source/com_categories/src/category-autosave-controller.es6.js'), /com_autosave\.create-binding/);

  // The workflow stage sibling of the enabled transition/workflow contexts is enabled.
  assert.ok(
    asset('com_workflow', 'com_workflow.stage-autosave').dependencies.includes('com_autosave.create-binding'),
    'com_workflow.stage-autosave is enabled.',
  );
});
