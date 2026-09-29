ALTER TABLE "#__autosave_generations"
  ADD COLUMN "closed_at" timestamp without time zone;

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
