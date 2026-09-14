-- Eligibility-only schema. Loaded through the test-local connection created in
-- FieldsFilterEligibilityDatabaseTest, which applies a dedicated table prefix, so these
-- DROP/CREATE statements only ever touch that test's objects and never the shared
-- Joomla integration tables.
DROP TABLE IF EXISTS `#__fields`;
DROP TABLE IF EXISTS `#__fields_groups`;
DROP TABLE IF EXISTS `#__fields_categories`;
DROP TABLE IF EXISTS `#__viewlevels`;
DROP TABLE IF EXISTS `#__languages`;
DROP TABLE IF EXISTS `#__users`;

CREATE TABLE `#__fields` (
    `id` int NOT NULL,
    `title` varchar(255) NOT NULL DEFAULT '',
    `name` varchar(255) NOT NULL DEFAULT '',
    `checked_out` int DEFAULT NULL,
    `checked_out_time` datetime DEFAULT NULL,
    `note` varchar(255) NOT NULL DEFAULT '',
    `state` tinyint NOT NULL DEFAULT 0,
    `access` int unsigned NOT NULL DEFAULT 1,
    `created_time` datetime DEFAULT NULL,
    `created_user_id` int unsigned NOT NULL DEFAULT 0,
    `ordering` int NOT NULL DEFAULT 0,
    `language` varchar(7) NOT NULL DEFAULT '*',
    `fieldparams` text,
    `params` text,
    `type` varchar(255) NOT NULL DEFAULT '',
    `default_value` text,
    `context` varchar(255) NOT NULL DEFAULT '',
    `group_id` int unsigned NOT NULL DEFAULT 0,
    `label` varchar(255) NOT NULL DEFAULT '',
    `description` text,
    `required` tinyint NOT NULL DEFAULT 0,
    `only_use_in_subform` tinyint NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `#__fields_groups` (
    `id` int unsigned NOT NULL,
    `title` varchar(255) NOT NULL DEFAULT '',
    `access` int unsigned NOT NULL DEFAULT 1,
    `state` tinyint NOT NULL DEFAULT 0,
    `note` varchar(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `#__fields_categories` (
    `field_id` int unsigned NOT NULL,
    `category_id` int unsigned NOT NULL,
    KEY `idx_field_id` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `#__viewlevels` (
    `id` int unsigned NOT NULL,
    `title` varchar(100) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `#__languages` (
    `lang_code` varchar(7) NOT NULL,
    `title` varchar(255) NOT NULL DEFAULT '',
    `image` varchar(255) DEFAULT NULL,
    KEY `idx_lang_code` (`lang_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `#__users` (
    `id` int unsigned NOT NULL,
    `name` varchar(400) NOT NULL DEFAULT '',
    `username` varchar(150) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__users` (`id`, `name`, `username`) VALUES
    (42, 'Filter User', 'filter-user');

INSERT INTO `#__languages` (`lang_code`, `title`, `image`) VALUES
    ('*', 'All', NULL),
    ('en-GB', 'English (UK)', 'en-GB');

INSERT INTO `#__viewlevels` (`id`, `title`) VALUES
    (1, 'Public'),
    (99, 'Restricted');

INSERT INTO `#__fields_groups` (`id`, `title`, `access`, `state`, `note`) VALUES
    (910001, 'Unpublished group', 1, 0, ''),
    (910002, 'Restricted group', 99, 1, '');

INSERT INTO `#__fields` (
    `id`, `title`, `name`, `note`, `state`, `access`, `ordering`, `language`,
    `fieldparams`, `params`, `type`, `context`, `group_id`, `label`, `description`,
    `required`, `only_use_in_subform`
) VALUES
    (910001, 'Eligible', 'eligible', '', 1, 1, 1, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Eligible', '', 0, 0),
    (910002, 'Unpublished', 'unpublished', '', 0, 1, 2, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Unpublished', '', 0, 0),
    (910003, 'Group offline', 'group-offline', '', 1, 1, 3, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 910001, 'Unpublished group', '', 0, 0),
    (910004, 'Access denied', 'access-denied', '', 1, 99, 4, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Restricted access', '', 0, 0),
    (910005, 'Group access denied', 'group-access-denied', '', 1, 1, 5, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 910002, 'Restricted group', '', 0, 0),
    (910006, 'Category restricted', 'category-restricted', '', 1, 1, 6, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Category restricted', '', 0, 0),
    (910007, 'Language specific', 'language-specific', '', 1, 1, 7, 'en-GB', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Language specific', '', 0, 0),
    (910008, 'Subform only', 'subform-only', '', 1, 1, 8, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Subform only', '', 0, 1);

INSERT INTO `#__fields_categories` (`field_id`, `category_id`) VALUES
    (910006, 5);
