CREATE INDEX "#__autosave_continuation_activity" ON "#__autosave_continuations" ("last_activity_at");
CREATE INDEX "#__autosave_generation_global_expiry" ON "#__autosave_generations" ("state", "expires_at");
CREATE INDEX "#__autosave_canonical_continuation" ON "#__autosave_canonical_actions" ("continuation_id");

INSERT INTO "#__extensions" ("package_id", "name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 0, 'plg_task_autosave', 'plugin', 'autosave', 'task', 0, 1, 1, 0, 1, '', '{}', '', 10, 0
WHERE NOT EXISTS (
  SELECT 1 FROM "#__extensions" WHERE "type" = 'plugin' AND "element" = 'autosave' AND "folder" = 'task'
);

INSERT INTO "#__scheduler_tasks" ("asset_id", "title", "type", "execution_rules", "cron_rules", "state", "last_execution", "next_execution", "locked", "params", "created", "created_by")
SELECT 0, 'Autosave Retention Cleanup', 'autosave.cleanup', CONCAT('{"rule-type":"interval-hours","interval-hours":"24","exec-day":"01","exec-time":"', TO_CHAR(CURRENT_TIMESTAMP AT TIME ZONE 'UTC', 'HH24:00'), '"}'), '{"type":"interval","exp":"PT24H"}', 1, NULL, TO_TIMESTAMP(TO_CHAR(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' + INTERVAL '24 hours', 'YYYY-MM-DD HH24:00:00'), 'YYYY-MM-DD HH24:MI:SS'), NULL, '{"individual_log":false,"log_file":"","notifications":{"success_mail":"0","failure_mail":"1","fatal_failure_mail":"1","orphan_mail":"1"}}', CURRENT_TIMESTAMP AT TIME ZONE 'UTC', 0
WHERE NOT EXISTS (
  SELECT 1 FROM "#__scheduler_tasks" WHERE "type" = 'autosave.cleanup'
);
