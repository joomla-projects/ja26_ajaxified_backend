DROP TABLE IF EXISTS `#__autosave_canonical_actions`;
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
  KEY `idx_autosave_continuation_recovery` (`user_id`,`context`,`target_id`),
  KEY `idx_autosave_continuation_activity` (`last_activity_at`)
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
  `closed_at` datetime,
  `terminal_at` datetime,
  `retain_until` datetime,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_autosave_generation_public_id` (`public_id`),
  UNIQUE KEY `idx_autosave_generation_active` (`continuation_id`,`active_marker`),
  UNIQUE KEY `idx_autosave_generation_quota` (`user_id`,`quota_slot`),
  KEY `idx_autosave_generation_continuation` (`continuation_id`),
  KEY `idx_autosave_generation_expiry` (`user_id`,`state`,`expires_at`),
  KEY `idx_autosave_generation_global_expiry` (`state`,`expires_at`),
  KEY `idx_autosave_generation_retention` (`retain_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__autosave_canonical_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `continuation_id` bigint unsigned NOT NULL,
  `generation_id` bigint unsigned NOT NULL,
  `context` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_id` varbinary(764) NOT NULL,
  `intent` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_base_revision` varbinary(1020) NOT NULL,
  `outcome` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `final_target_id` varbinary(764),
  `final_base_revision` varbinary(1020),
  `failure_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `completed_at` datetime,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_autosave_canonical_public_id` (`public_id`),
  UNIQUE KEY `idx_autosave_canonical_generation` (`generation_id`),
  KEY `idx_autosave_canonical_continuation` (`continuation_id`),
  KEY `idx_autosave_canonical_owner` (`user_id`,`context`,`target_id`),
  KEY `idx_autosave_canonical_expiry` (`outcome`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
