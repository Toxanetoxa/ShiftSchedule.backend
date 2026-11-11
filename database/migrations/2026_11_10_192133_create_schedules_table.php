<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE schedules (
    id          uuid PRIMARY KEY,
    name        text NOT NULL,
    time_zone   text NOT NULL DEFAULT 'Europe/Moscow',
    version     bigint NOT NULL DEFAULT 1,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT schedules_name_valid
        CHECK (char_length(btrim(name)) BETWEEN 1 AND 160),
    CONSTRAINT schedules_time_zone_nonempty
        CHECK (char_length(btrim(time_zone)) BETWEEN 1 AND 100),
    CONSTRAINT schedules_version_positive CHECK (version >= 1)
);

CREATE OR REPLACE FUNCTION forbid_schedule_time_zone_change()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.time_zone IS DISTINCT FROM OLD.time_zone THEN
        RAISE EXCEPTION 'The schedule time zone is immutable after creation'
            USING ERRCODE = '23514', CONSTRAINT = 'schedules_time_zone_immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER schedules_time_zone_immutable
    BEFORE UPDATE OF time_zone ON schedules
    FOR EACH ROW
    EXECUTE FUNCTION forbid_schedule_time_zone_change();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS schedules;
DROP FUNCTION IF EXISTS forbid_schedule_time_zone_change();
SQL);
    }
};
