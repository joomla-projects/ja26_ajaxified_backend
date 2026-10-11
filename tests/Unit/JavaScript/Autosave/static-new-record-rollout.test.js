import assert from 'node:assert/strict';
import test from 'node:test';

import { validateClientConfiguration } from '../../../../media_source/com_banners/src/client-autosave-controller.es6.js';
import { validateConfiguration as validateBannerConfiguration } from '../../../../media_source/com_banners/src/banner-autosave-controller.es6.js';
import { validateTourConfiguration } from '../../../../media_source/com_guidedtours/src/tour-autosave-controller.es6.js';
import { validateConfiguration as validateLanguageConfiguration } from '../../../../media_source/com_languages/src/language-autosave-controller.es6.js';
import { validateConfiguration as validateTagConfiguration } from '../../../../media_source/com_tags/src/tag-autosave-controller.es6.js';
import { validateConfiguration as validateNewsfeedConfiguration } from '../../../../media_source/com_newsfeeds/src/newsfeed-autosave-controller.es6.js';
import { validateConfiguration as validateFinderConfiguration } from '../../../../media_source/com_finder/src/filter-autosave-controller.es6.js';

const specs = [
  ['com_banners.client', validateClientConfiguration, ['name', 'contact', 'email', 'extrainfo', 'metakey', 'metakey_prefix', 'version_note', 'purchase_type', 'track_impressions', 'track_clicks', 'own_prefix']],
  ['com_banners.banner', validateBannerConfiguration, ['name', 'alias', 'description', 'type', 'custombannercode', 'clickurl', 'version_note', 'publish_up', 'publish_down', 'imageurl', 'width', 'height', 'alt', 'metakey', 'metakey_prefix', 'own_prefix', 'catid', 'cid']],
  ['com_guidedtours.tour', validateTourConfiguration, ['title', 'uid', 'description', 'note', 'url', 'autostart']],
  ['com_languages.language', validateLanguageConfiguration, ['title', 'title_native', 'description', 'metadesc', 'sitename']],
  ['com_tags.tag', validateTagConfiguration, ['title', 'note', 'description', 'version_note', 'metadesc', 'metakey', 'parent_id']],
  ['com_newsfeeds.newsfeed', validateNewsfeedConfiguration, ['name', 'description', 'link', 'version_note', 'numarticles', 'cache_time', 'metadesc', 'metakey', 'catid']],
  ['com_finder.filter', validateFinderConfiguration, ['title', 'alias', 'created', 'created_by', 'created_by_alias', 'state', 'w1', 'd1', 'w2', 'd2']],
];

for (const [context, validate, fields] of specs) {
  test(`${context} accepts only an unresolved null target in create mode`, () => {
    const configuration = {
      enabled: true,
      context,
      mode: 'create',
      targetId: null,
      payloadSchemaVersion: ['com_banners.banner', 'com_tags.tag', 'com_newsfeeds.newsfeed'].includes(context) ? 2 : 1,
      formId: 'item-form',
      fieldIds: Object.fromEntries(fields.map((field) => [field, `jform_${field}`])),
    };

    assert.equal(validate(configuration).targetId, null);
    assert.equal(validate(configuration).mode, 'create');
    assert.throws(() => validate({ ...configuration, targetId: '7' }), TypeError);
    assert.throws(() => validate({ ...configuration, mode: 'existing' }), TypeError);
  });
}
