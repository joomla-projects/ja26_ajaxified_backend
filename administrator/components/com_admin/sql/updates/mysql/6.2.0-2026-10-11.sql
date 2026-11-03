-- Private autosave drafts.
CREATE TABLE IF NOT EXISTS `#__autosave_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `draft_id` char(32) NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `owner_slot` smallint NOT NULL,
  `row_version` bigint NOT NULL DEFAULT 0,
  `context` varchar(255) NOT NULL,
  `target_id` varchar(255) DEFAULT NULL,
  `scope` text NOT NULL,
  `schema_version` int unsigned NOT NULL,
  `fields` text NOT NULL,
  `baseline_revision` varchar(64) DEFAULT NULL,
  `client_revision` bigint unsigned NOT NULL DEFAULT 0,
  `retired_revision` bigint unsigned NOT NULL DEFAULT 0,
  `payload` mediumtext DEFAULT NULL,
  `payload_digest` char(64) DEFAULT NULL,
  `result_id` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_autosave_draft_public` (`draft_id`),
  UNIQUE KEY `idx_autosave_owner_slot` (`owner_id`,`owner_slot`),
  KEY `idx_autosave_draft_owner` (`owner_id`,`context`,`target_id`),
  KEY `idx_autosave_draft_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__extensions` (`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 0, 'plg_system_autosave', 'plugin', 'autosave', 'system', 0, 1, 1, 0, 1, '', '{}', '', 10, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `#__extensions` WHERE `type` = 'plugin' AND `element` = 'autosave' AND `folder` = 'system'
);

INSERT INTO `#__extensions` (`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 0, 'plg_task_autosave', 'plugin', 'autosave', 'task', 0, 1, 1, 0, 1, '', '{}', '', 10, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `#__extensions` WHERE `type` = 'plugin' AND `element` = 'autosave' AND `folder` = 'task'
);

INSERT INTO `#__scheduler_tasks` (`asset_id`, `title`, `type`, `execution_rules`, `cron_rules`, `state`, `last_execution`, `next_execution`, `locked`, `params`, `created`, `created_by`)
SELECT 0, 'Autosave Retention Cleanup', 'autosave.cleanup', CONCAT('{"rule-type":"interval-hours","interval-hours":"1","exec-day":"01","exec-time":"', TIME_FORMAT(NOW(), '%H:00'), '"}'), '{"type":"interval","exp":"PT1H"}', 1, NULL, DATE_FORMAT(NOW() + INTERVAL 1 HOUR, '%Y-%m-%d %H:00:00'), NULL, '{"individual_log":false,"log_file":"","notifications":{"success_mail":"0","failure_mail":"1","fatal_failure_mail":"1","orphan_mail":"1"}}', NOW(), 0
WHERE NOT EXISTS (
  SELECT 1 FROM `#__scheduler_tasks` WHERE `type` = 'autosave.cleanup'
);
