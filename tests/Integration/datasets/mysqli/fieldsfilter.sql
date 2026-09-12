DROP TABLE IF EXISTS `#__cff_items`;

CREATE TABLE `#__cff_items` (
    `id` varchar(64) NOT NULL,
    `kind` varchar(16) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__fields_values` (
    `field_id` int unsigned NOT NULL,
    `item_id` varchar(255) NOT NULL COMMENT 'Allow references to items which have strings as ids, eg. none db systems.',
    `value` mediumtext,
    KEY `idx_field_id` (`field_id`),
    KEY `idx_item_id` (`item_id`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
