INSERT INTO `#__extensions`
(`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`)
SELECT 0, 'com_autosave', 'component', 'com_autosave', '', 1, 1, 1, 1, 1, '', '', ''
WHERE NOT EXISTS (
  SELECT 1 FROM `#__extensions`
  WHERE `type` = 'component' AND `element` = 'com_autosave' AND `client_id` = 1
);
