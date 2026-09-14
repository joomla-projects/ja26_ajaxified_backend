-- Eligibility-only schema. Loaded through the test-local connection created in
-- FieldsFilterEligibilityDatabaseTest, which applies a dedicated table prefix, so these
-- DROP/CREATE statements only ever touch that test's objects and never the shared
-- Joomla integration tables.
DROP TABLE IF EXISTS "#__fields";
DROP TABLE IF EXISTS "#__fields_groups";
DROP TABLE IF EXISTS "#__fields_categories";
DROP TABLE IF EXISTS "#__viewlevels";
DROP TABLE IF EXISTS "#__languages";
DROP TABLE IF EXISTS "#__users";

CREATE TABLE "#__fields" (
    "id" integer NOT NULL,
    "title" varchar(255) DEFAULT '' NOT NULL,
    "name" varchar(255) DEFAULT '' NOT NULL,
    "checked_out" integer DEFAULT NULL,
    "checked_out_time" timestamp DEFAULT NULL,
    "note" varchar(255) DEFAULT '' NOT NULL,
    "state" smallint DEFAULT 0 NOT NULL,
    "access" integer DEFAULT 1 NOT NULL,
    "created_time" timestamp DEFAULT NULL,
    "created_user_id" integer DEFAULT 0 NOT NULL,
    "ordering" integer DEFAULT 0 NOT NULL,
    "language" varchar(7) DEFAULT '*' NOT NULL,
    "fieldparams" text,
    "params" text,
    "type" varchar(255) DEFAULT '' NOT NULL,
    "default_value" text,
    "context" varchar(255) DEFAULT '' NOT NULL,
    "group_id" integer DEFAULT 0 NOT NULL,
    "label" varchar(255) DEFAULT '' NOT NULL,
    "description" text,
    "required" smallint DEFAULT 0 NOT NULL,
    "only_use_in_subform" smallint DEFAULT 0 NOT NULL,
    PRIMARY KEY ("id")
);

CREATE TABLE "#__fields_groups" (
    "id" integer NOT NULL,
    "title" varchar(255) DEFAULT '' NOT NULL,
    "access" integer DEFAULT 1 NOT NULL,
    "state" smallint DEFAULT 0 NOT NULL,
    "note" varchar(255) DEFAULT '' NOT NULL,
    PRIMARY KEY ("id")
);

CREATE TABLE "#__fields_categories" (
    "field_id" integer NOT NULL,
    "category_id" integer NOT NULL
);
CREATE INDEX "#__fields_categories_idx_field_id" ON "#__fields_categories" ("field_id");

CREATE TABLE "#__viewlevels" (
    "id" integer NOT NULL,
    "title" varchar(100) DEFAULT '' NOT NULL,
    PRIMARY KEY ("id")
);

CREATE TABLE "#__languages" (
    "lang_code" varchar(7) NOT NULL,
    "title" varchar(255) DEFAULT '' NOT NULL,
    "image" varchar(255) DEFAULT NULL
);
CREATE INDEX "#__languages_idx_lang_code" ON "#__languages" ("lang_code");

CREATE TABLE "#__users" (
    "id" integer NOT NULL,
    "name" varchar(400) DEFAULT '' NOT NULL,
    "username" varchar(150) DEFAULT '' NOT NULL,
    PRIMARY KEY ("id")
);

INSERT INTO "#__users" ("id", "name", "username") VALUES
    (42, 'Filter User', 'filter-user');

INSERT INTO "#__languages" ("lang_code", "title", "image") VALUES
    ('*', 'All', NULL),
    ('en-GB', 'English (UK)', 'en-GB');

INSERT INTO "#__viewlevels" ("id", "title") VALUES
    (1, 'Public'),
    (99, 'Restricted');

INSERT INTO "#__fields_groups" ("id", "title", "access", "state", "note") VALUES
    (910001, 'Unpublished group', 1, 0, ''),
    (910002, 'Restricted group', 99, 1, '');

INSERT INTO "#__fields" (
    "id", "title", "name", "note", "state", "access", "ordering", "language",
    "fieldparams", "params", "type", "context", "group_id", "label", "description",
    "required", "only_use_in_subform"
) VALUES
    (910001, 'Eligible', 'eligible', '', 1, 1, 1, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Eligible', '', 0, 0),
    (910002, 'Unpublished', 'unpublished', '', 0, 1, 2, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Unpublished', '', 0, 0),
    (910003, 'Group offline', 'group-offline', '', 1, 1, 3, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 910001, 'Unpublished group', '', 0, 0),
    (910004, 'Access denied', 'access-denied', '', 1, 99, 4, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Restricted access', '', 0, 0),
    (910005, 'Group access denied', 'group-access-denied', '', 1, 1, 5, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 910002, 'Restricted group', '', 0, 0),
    (910006, 'Category restricted', 'category-restricted', '', 1, 1, 6, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Category restricted', '', 0, 0),
    (910007, 'Language specific', 'language-specific', '', 1, 1, 7, 'en-GB', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Language specific', '', 0, 0),
    (910008, 'Subform only', 'subform-only', '', 1, 1, 8, '*', '{}', '{"show_in_admin_list_filter":1}', 'fixture-option', 'com_content.article', 0, 'Subform only', '', 0, 1);

INSERT INTO "#__fields_categories" ("field_id", "category_id") VALUES
    (910006, 5);
