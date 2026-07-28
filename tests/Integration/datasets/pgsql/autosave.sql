DROP TABLE IF EXISTS "#__autosave_generations";
DROP TABLE IF EXISTS "#__autosave_continuations";

CREATE TABLE IF NOT EXISTS "#__autosave_continuations" (
  "id" bigserial NOT NULL,
  "public_id" varchar(64) NOT NULL,
  "user_id" bigint NOT NULL,
  "context" varchar(255) NOT NULL,
  "target_id" varchar(191) NOT NULL,
  "initialization_key" varchar(191) NOT NULL,
  "created_at" timestamp without time zone NOT NULL,
  "last_activity_at" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__autosave_continuation_public_id" UNIQUE ("public_id"),
  CONSTRAINT "#__autosave_continuation_initialization" UNIQUE ("user_id", "initialization_key")
);
CREATE INDEX "#__autosave_continuation_recovery" ON "#__autosave_continuations" ("user_id", "context", "target_id");

CREATE TABLE IF NOT EXISTS "#__autosave_generations" (
  "id" bigserial NOT NULL,
  "public_id" varchar(64) NOT NULL,
  "continuation_id" bigint NOT NULL,
  "user_id" bigint NOT NULL,
  "base_revision" varchar(255) NOT NULL,
  "state" varchar(16) NOT NULL,
  "client_revision" bigint DEFAULT 0 NOT NULL,
  "payload" text,
  "payload_schema_version" integer,
  "active_marker" smallint,
  "quota_slot" integer,
  "created_at" timestamp without time zone NOT NULL,
  "updated_at" timestamp without time zone NOT NULL,
  "expires_at" timestamp without time zone NOT NULL,
  "terminal_at" timestamp without time zone,
  "retain_until" timestamp without time zone,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__autosave_generation_public_id" UNIQUE ("public_id"),
  CONSTRAINT "#__autosave_generation_active" UNIQUE ("continuation_id", "active_marker"),
  CONSTRAINT "#__autosave_generation_quota" UNIQUE ("user_id", "quota_slot")
);
CREATE INDEX "#__autosave_generation_continuation" ON "#__autosave_generations" ("continuation_id");
CREATE INDEX "#__autosave_generation_expiry" ON "#__autosave_generations" ("user_id", "state", "expires_at");
CREATE INDEX "#__autosave_generation_retention" ON "#__autosave_generations" ("retain_until");
