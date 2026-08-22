DROP TABLE IF EXISTS "#__autosave_canonical_actions";
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
  "payload_digest" varchar(64),
  "payload_schema_version" integer,
  "active_marker" smallint,
  "quota_slot" integer,
  "created_at" timestamp without time zone NOT NULL,
  "updated_at" timestamp without time zone NOT NULL,
  "expires_at" timestamp without time zone NOT NULL,
  "closed_at" timestamp without time zone,
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

CREATE TABLE IF NOT EXISTS "#__autosave_canonical_actions" (
  "id" bigserial NOT NULL,
  "public_id" varchar(64) NOT NULL,
  "user_id" bigint NOT NULL,
  "continuation_id" bigint NOT NULL,
  "generation_id" bigint NOT NULL,
  "context" varchar(255) NOT NULL,
  "target_id" varchar(191) NOT NULL,
  "intent" varchar(32) NOT NULL,
  "expected_base_revision" varchar(255) NOT NULL,
  "outcome" varchar(16) NOT NULL,
  "final_target_id" varchar(191),
  "final_base_revision" varchar(255),
  "failure_code" varchar(64),
  "created_at" timestamp without time zone NOT NULL,
  "updated_at" timestamp without time zone NOT NULL,
  "expires_at" timestamp without time zone NOT NULL,
  "completed_at" timestamp without time zone,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__autosave_canonical_public_id" UNIQUE ("public_id"),
  CONSTRAINT "#__autosave_canonical_generation" UNIQUE ("generation_id")
);
CREATE INDEX "#__autosave_canonical_owner" ON "#__autosave_canonical_actions" ("user_id", "context", "target_id");
CREATE INDEX "#__autosave_canonical_expiry" ON "#__autosave_canonical_actions" ("outcome", "expires_at");
