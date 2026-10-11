ALTER TABLE `#__autosave_continuations`
  ADD COLUMN `create_scope` varbinary(1020) NULL AFTER `initialization_key`;
