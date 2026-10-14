-- Private recoverable draft instances. Canonical records remain native Joomla records.
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
CREATE INDEX IF NOT EXISTS "#__autosave_draft_owner" ON "#__autosave_drafts" ("owner_id","context","target_id");
CREATE INDEX IF NOT EXISTS "#__autosave_draft_expiry" ON "#__autosave_drafts" ("expires_at");

INSERT INTO "#__extensions"
("package_id", "name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 0, 'com_autosave', 'component', 'com_autosave', '', 1, 1, 1, 1, 1, '', '', '', 0, 0
WHERE NOT EXISTS (
  SELECT 1 FROM "#__extensions"
  WHERE "type" = 'component' AND "element" = 'com_autosave' AND "client_id" = 1
);

INSERT INTO "#__extensions" ("package_id", "name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 0, 'plg_task_autosave', 'plugin', 'autosave', 'task', 0, 1, 1, 0, 1, '', '{}', '', 10, 0
WHERE NOT EXISTS (
  SELECT 1 FROM "#__extensions" WHERE "type" = 'plugin' AND "element" = 'autosave' AND "folder" = 'task'
);

INSERT INTO "#__scheduler_tasks" ("asset_id", "title", "type", "execution_rules", "cron_rules", "state", "last_execution", "next_execution", "locked", "params", "created", "created_by")
SELECT 0, 'Autosave Retention Cleanup', 'autosave.cleanup', CONCAT('{"rule-type":"interval-hours","interval-hours":"1","exec-day":"01","exec-time":"', TO_CHAR(CURRENT_TIMESTAMP AT TIME ZONE 'UTC', 'HH24:00'), '"}'), '{"type":"interval","exp":"PT1H"}', 1, NULL, TO_TIMESTAMP(TO_CHAR(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' + INTERVAL '1 hour', 'YYYY-MM-DD HH24:00:00'), 'YYYY-MM-DD HH24:MI:SS'), NULL, '{"individual_log":false,"log_file":"","notifications":{"success_mail":"0","failure_mail":"1","fatal_failure_mail":"1","orphan_mail":"1"}}', CURRENT_TIMESTAMP AT TIME ZONE 'UTC', 0
WHERE NOT EXISTS (
  SELECT 1 FROM "#__scheduler_tasks" WHERE "type" = 'autosave.cleanup'
);
