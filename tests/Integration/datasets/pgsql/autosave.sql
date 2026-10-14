CREATE TABLE IF NOT EXISTS "#__users" (
  "id" serial NOT NULL,
  "name" varchar(255) DEFAULT '' NOT NULL,
  "username" varchar(150) DEFAULT '' NOT NULL,
  "email" varchar(100) DEFAULT '' NOT NULL,
  "password" varchar(100) DEFAULT '' NOT NULL,
  "block" smallint DEFAULT 0 NOT NULL,
  "sendEmail" smallint DEFAULT 0,
  "registerDate" timestamp without time zone NOT NULL,
  "lastvisitDate" timestamp without time zone,
  "activation" varchar(100) DEFAULT '' NOT NULL,
  "params" text NOT NULL,
  "lastResetTime" timestamp without time zone,
  "resetCount" bigint DEFAULT 0 NOT NULL,
  "otpKey" varchar(1000) DEFAULT '' NOT NULL,
  "otep" varchar(1000) DEFAULT '' NOT NULL,
  "requireReset" smallint DEFAULT 0,
  "authProvider" varchar(100) DEFAULT '' NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__users_idx_username" UNIQUE ("username")
);

CREATE INDEX "#__users_idx_name" ON "#__users" ("name");

CREATE INDEX "#__users_idx_block" ON "#__users" ("block");

CREATE INDEX "#__users_email" ON "#__users" ("email");

CREATE INDEX "#__users_email_lower" ON "#__users" (lower("email"));

CREATE TABLE IF NOT EXISTS "#__autosave_drafts" (
  "id" bigserial NOT NULL,
  "draft_id" char(32) NOT NULL,
  "owner_id" integer NOT NULL,
  "context" varchar(255) NOT NULL,
  "target_id" varchar(255) DEFAULT NULL,
  "scope" text NOT NULL,
  "schema_version" integer NOT NULL,
  "fields" text NOT NULL,
  "baseline_revision" varchar(64) DEFAULT NULL,
  "client_revision" bigint NOT NULL DEFAULT 0,
  "retired_revision" bigint NOT NULL DEFAULT 0,
  "payload" text DEFAULT NULL,
  "payload_digest" char(64) DEFAULT NULL,
  "result_id" varchar(255) DEFAULT NULL,
  "created_at" timestamp without time zone NOT NULL,
  "updated_at" timestamp without time zone NOT NULL,
  "expires_at" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__autosave_draft_public" UNIQUE ("draft_id")
);

CREATE INDEX "#__autosave_draft_owner" ON "#__autosave_drafts" ("owner_id","context","target_id");

CREATE INDEX "#__autosave_draft_expiry" ON "#__autosave_drafts" ("expires_at");

CREATE TABLE IF NOT EXISTS "#__extensions" (
  "extension_id" serial NOT NULL,
  "package_id" bigint DEFAULT 0 NOT NULL,
  "name" varchar(100) NOT NULL,
  "type" varchar(20) NOT NULL,
  "element" varchar(100) NOT NULL,
  "changelogurl" text,
  "folder" varchar(100) NOT NULL,
  "client_id" smallint NOT NULL,
  "enabled" smallint DEFAULT 0 NOT NULL,
  "access" bigint DEFAULT 1 NOT NULL,
  "protected" smallint DEFAULT 0 NOT NULL,
  "locked" smallint DEFAULT 0 NOT NULL,
  "manifest_cache" text NOT NULL,
  "params" text NOT NULL,
  "custom_data" text NOT NULL,
  "checked_out" integer,
  "checked_out_time" timestamp without time zone,
  "ordering" bigint DEFAULT 0,
  "state" bigint DEFAULT 0,
  "note" varchar(255),
  PRIMARY KEY ("extension_id")
);

CREATE INDEX "#__extensions_element_clientid" ON "#__extensions" ("element", "client_id");

CREATE INDEX "#__extensions_element_folder_clientid" ON "#__extensions" ("element", "folder", "client_id");

CREATE INDEX "#__extensions_extension" ON "#__extensions" ("type", "element", "folder", "client_id");

CREATE TABLE IF NOT EXISTS "#__scheduler_tasks" (
  "id" serial NOT NULL,
  "asset_id" bigint DEFAULT 0 NOT NULL,
  "title" varchar(255) NOT NULL,
  "type" varchar(128) NOT NULL,
  "execution_rules" text,
  "cron_rules" text,
  "state" smallint DEFAULT 0 NOT NULL,
  "last_exit_code" integer DEFAULT 0 NOT NULL,
  "last_execution" timestamp without time zone,
  "next_execution" timestamp without time zone,
  "times_executed" integer DEFAULT 0 NOT NULL,
  "times_failed" integer DEFAULT 0,
  "locked" timestamp without time zone,
  "priority" smallint DEFAULT 0 NOT NULL,
  "ordering" bigint DEFAULT 0 NOT NULL,
  "cli_exclusive" smallint DEFAULT 0 NOT NULL,
  "params" text NOT NULL,
  "note" text,
  "created" timestamp without time zone NOT NULL,
  "created_by" bigint DEFAULT 0 NOT NULL,
  "checked_out" integer,
  "checked_out_time" timestamp without time zone,
  PRIMARY KEY ("id")
);

CREATE INDEX "#__scheduler_tasks_idx_type" ON "#__scheduler_tasks" ("type");

CREATE INDEX "#__scheduler_tasks_idx_state" ON "#__scheduler_tasks" ("state");

CREATE INDEX "#__scheduler_tasks_idx_last_exit" ON "#__scheduler_tasks" ("last_exit_code");

CREATE INDEX "#__scheduler_tasks_idx_next_exec" ON "#__scheduler_tasks" ("next_execution");

CREATE INDEX "#__scheduler_tasks_idx_locked" ON "#__scheduler_tasks" ("locked");

CREATE INDEX "#__scheduler_tasks_idx_priority" ON "#__scheduler_tasks" ("priority");

CREATE INDEX "#__scheduler_tasks_idx_cli_exclusive" ON "#__scheduler_tasks" ("cli_exclusive");

CREATE INDEX "#__scheduler_tasks_idx_checked_out" ON "#__scheduler_tasks" ("checked_out");
