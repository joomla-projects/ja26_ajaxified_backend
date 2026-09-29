ALTER TABLE `#__autosave_generations`
  ADD COLUMN `payload_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin AFTER `payload`;
