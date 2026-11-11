<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE shift_templates (
    id              uuid PRIMARY KEY,
    schedule_id     uuid NOT NULL,
    name            text NOT NULL,
    color_key       text NOT NULL,
    start_time      time without time zone NOT NULL,
    end_time        time without time zone NOT NULL,
    end_day_offset  smallint NOT NULL DEFAULT 0,
    sort_order      integer NOT NULL DEFAULT 0,
    is_active       boolean NOT NULL DEFAULT true,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT shift_templates_schedule_fk
        FOREIGN KEY (schedule_id) REFERENCES schedules (id) ON DELETE RESTRICT,
    CONSTRAINT shift_templates_schedule_id_id_uq UNIQUE (schedule_id, id),
    CONSTRAINT shift_templates_name_valid
        CHECK (char_length(btrim(name)) BETWEEN 1 AND 80),
    CONSTRAINT shift_templates_color_key_valid
        CHECK (color_key ~ '^[a-z][a-z0-9_-]{0,31}$'),
    CONSTRAINT shift_templates_day_offset_valid CHECK (end_day_offset IN (0, 1)),
    CONSTRAINT shift_templates_sort_order_nonnegative CHECK (sort_order >= 0),
    CONSTRAINT shift_templates_minute_precision CHECK (
        EXTRACT(SECOND FROM start_time) = 0
        AND EXTRACT(SECOND FROM end_time) = 0
        AND start_time < TIME '24:00:00'
        AND end_time < TIME '24:00:00'
    ),
    CONSTRAINT shift_templates_duration_valid CHECK (
        (end_time - start_time + end_day_offset * INTERVAL '1 day')
            >= INTERVAL '1 minute'
        AND (end_time - start_time + end_day_offset * INTERVAL '1 day')
            < INTERVAL '24 hours'
    )
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS shift_templates;
SQL);
    }
};
