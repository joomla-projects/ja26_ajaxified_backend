DROP TABLE IF EXISTS `#__autosave_generations`;
DROP TABLE IF EXISTS `#__autosave_continuations`;

CREATE TABLE IF NOT EXISTS `#__autosave_continuations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `context` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_id` varbinary(764) NOT NULL,
  `initialization_key` varbinary(764) NOT NULL,
  `created_at` datetime NOT NULL,
  `last_activity_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_autosave_continuation_public_id` (`public_id`),
  UNIQUE KEY `idx_autosave_continuation_initialization` (`user_id`,`initialization_key`),
  KEY `idx_autosave_continuation_recovery` (`user_id`,`context`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__autosave_generations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `continuation_id` bigint unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `base_revision` varbinary(1020) NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_revision` bigint unsigned NOT NULL DEFAULT 0,
  `payload` mediumtext,
  `payload_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin,
  `payload_schema_version` int unsigned,
  `active_marker` tinyint unsigned,
  `quota_slot` int unsigned,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `terminal_at` datetime,
  `retain_until` datetime,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_autosave_generation_public_id` (`public_id`),
  UNIQUE KEY `idx_autosave_generation_active` (`continuation_id`,`active_marker`),
  UNIQUE KEY `idx_autosave_generation_quota` (`user_id`,`quota_slot`),
  KEY `idx_autosave_generation_continuation` (`continuation_id`),
  KEY `idx_autosave_generation_expiry` (`user_id`,`state`,`expires_at`),
  KEY `idx_autosave_generation_retention` (`retain_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
