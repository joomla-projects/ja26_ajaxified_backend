ALTER TABLE `#__autosave_continuations`
  ADD KEY `idx_autosave_continuation_activity` (`last_activity_at`);

ALTER TABLE `#__autosave_generations`
  ADD KEY `idx_autosave_generation_global_expiry` (`state`,`expires_at`);

ALTER TABLE `#__autosave_canonical_actions`
  ADD KEY `idx_autosave_canonical_continuation` (`continuation_id`);

INSERT INTO `#__extensions` (`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 0, 'plg_task_autosave', 'plugin', 'autosave', 'task', 0, 1, 1, 0, 1, '', '{}', '', 10, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `#__extensions` WHERE `type` = 'plugin' AND `element` = 'autosave' AND `folder` = 'task'
);

INSERT INTO `#__scheduler_tasks` (`asset_id`, `title`, `type`, `execution_rules`, `cron_rules`, `state`, `last_execution`, `next_execution`, `locked`, `params`, `created`, `created_by`)
SELECT 0, 'Autosave Retention Cleanup', 'autosave.cleanup', CONCAT('{"rule-type":"interval-hours","interval-hours":"24","exec-day":"01","exec-time":"', TIME_FORMAT(NOW(), '%H:00'), '"}'), '{"type":"interval","exp":"PT24H"}', 1, NULL, DATE_FORMAT(NOW() + INTERVAL 24 HOUR, '%Y-%m-%d %H:00:00'), NULL, '{"individual_log":false,"log_file":"","notifications":{"success_mail":"0","failure_mail":"1","fatal_failure_mail":"1","orphan_mail":"1"}}', NOW(), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `#__scheduler_tasks` WHERE `type` = 'autosave.cleanup'
);
