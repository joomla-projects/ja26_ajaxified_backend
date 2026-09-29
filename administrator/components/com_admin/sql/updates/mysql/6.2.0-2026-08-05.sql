ALTER TABLE `#__autosave_generations`
  ADD COLUMN `closed_at` datetime NULL AFTER `expires_at`;

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
  KEY `idx_autosave_canonical_owner` (`user_id`,`context`,`target_id`),
  KEY `idx_autosave_canonical_expiry` (`outcome`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
