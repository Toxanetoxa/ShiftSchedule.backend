<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE schedule_publications (
    id                      uuid PRIMARY KEY,
    schedule_id             uuid NOT NULL,
    from_date               date NOT NULL,
    to_date                 date NOT NULL,
    source_version          bigint NOT NULL,
    snapshot_schema_version smallint NOT NULL DEFAULT 1,
    snapshot                jsonb NOT NULL,
    created_by              text NOT NULL DEFAULT 'demo-manager',
    created_at              timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT schedule_publications_schedule_fk
        FOREIGN KEY (schedule_id) REFERENCES schedules (id) ON DELETE RESTRICT,
    CONSTRAINT schedule_publications_period_valid CHECK (
        isfinite(from_date)
        AND isfinite(to_date)
        AND from_date < to_date
    ),
    CONSTRAINT schedule_publications_source_version_positive CHECK (source_version >= 1),
    CONSTRAINT schedule_publications_snapshot_schema_supported CHECK (snapshot_schema_version = 1),
    CONSTRAINT schedule_publications_snapshot_object CHECK (jsonb_typeof(snapshot) = 'object'),
    CONSTRAINT schedule_publications_created_by_valid
        CHECK (char_length(btrim(created_by)) BETWEEN 1 AND 160),
    CONSTRAINT schedule_publications_same_version_period_uq
        UNIQUE (schedule_id, from_date, to_date, source_version)
);

CREATE INDEX schedule_publications_schedule_created_idx
    ON schedule_publications (schedule_id, created_at DESC, id);

CREATE OR REPLACE FUNCTION forbid_schedule_publication_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Schedule publications are immutable'
        USING ERRCODE = '55000';
END;
$$;

CREATE TRIGGER schedule_publications_immutable
    BEFORE UPDATE OR DELETE ON schedule_publications
    FOR EACH ROW
    EXECUTE FUNCTION forbid_schedule_publication_mutation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS schedule_publications;
DROP FUNCTION IF EXISTS forbid_schedule_publication_mutation();
SQL);
    }
};
