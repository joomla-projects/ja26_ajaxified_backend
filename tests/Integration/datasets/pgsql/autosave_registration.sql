DROP TABLE IF EXISTS "#__extensions";
CREATE TABLE "#__extensions" (
  "extension_id" serial NOT NULL,
  "package_id" bigint DEFAULT 0 NOT NULL,
  "name" varchar(100) NOT NULL,
  "type" varchar(20) NOT NULL,
  "element" varchar(100) NOT NULL,
  "folder" varchar(100) NOT NULL,
  "client_id" smallint NOT NULL,
  "enabled" smallint DEFAULT 0 NOT NULL,
  "access" bigint DEFAULT 1 NOT NULL,
  "protected" smallint DEFAULT 0 NOT NULL,
  "locked" smallint DEFAULT 0 NOT NULL,
  "manifest_cache" text NOT NULL,
  "params" text NOT NULL,
  "custom_data" text NOT NULL,
  "ordering" bigint DEFAULT 0,
  "state" bigint DEFAULT 0,
  PRIMARY KEY ("extension_id")
);
CREATE INDEX "#__extensions_extension" ON "#__extensions" ("type", "element", "folder", "client_id");
