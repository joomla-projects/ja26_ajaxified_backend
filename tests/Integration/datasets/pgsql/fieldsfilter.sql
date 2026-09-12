DROP TABLE IF EXISTS "#__cff_items";

CREATE TABLE "#__cff_items" (
    "id" varchar(64) NOT NULL,
    "kind" varchar(16) NOT NULL
);

CREATE TABLE IF NOT EXISTS "#__fields_values" (
    "field_id" bigint DEFAULT 0 NOT NULL,
    "item_id" varchar(255) DEFAULT '' NOT NULL,
    "value" text
);
