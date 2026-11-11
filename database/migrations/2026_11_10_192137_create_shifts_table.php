<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE shifts (
    id                  uuid PRIMARY KEY,
    schedule_id         uuid NOT NULL,
    employee_id         uuid NOT NULL,
    work_date           date NOT NULL,
    starts_at           timestamptz NOT NULL,
    ends_at             timestamptz NOT NULL,
    source_template_id  uuid,
    label_snapshot      text NOT NULL,
    color_key_snapshot  text NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT shifts_employee_in_schedule_fk
        FOREIGN KEY (schedule_id, employee_id)
        REFERENCES schedule_employees (schedule_id, employee_id)
        ON DELETE RESTRICT,
    CONSTRAINT shifts_template_in_schedule_fk
        FOREIGN KEY (schedule_id, source_template_id)
        REFERENCES shift_templates (schedule_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT shifts_work_date_finite CHECK (isfinite(work_date)),
    CONSTRAINT shifts_interval_finite CHECK (isfinite(starts_at) AND isfinite(ends_at)),
    CONSTRAINT shifts_minute_precision CHECK (
        EXTRACT(SECOND FROM starts_at AT TIME ZONE 'UTC') = 0
        AND EXTRACT(SECOND FROM ends_at AT TIME ZONE 'UTC') = 0
    ),
    CONSTRAINT shifts_duration_valid CHECK (
        ends_at - starts_at >= INTERVAL '1 minute'
        AND ends_at - starts_at < INTERVAL '24 hours'
    ),
    CONSTRAINT shifts_label_valid
        CHECK (char_length(btrim(label_snapshot)) BETWEEN 1 AND 80),
    CONSTRAINT shifts_color_key_valid
        CHECK (color_key_snapshot ~ '^[a-z][a-z0-9_-]{0,31}$'),
    CONSTRAINT shifts_unique_cell
        UNIQUE (schedule_id, employee_id, work_date)
        DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT shifts_no_overlap
        EXCLUDE USING gist (
            employee_id WITH =,
            tstzrange(starts_at, ends_at, '[)') WITH &&
        )
        DEFERRABLE INITIALLY IMMEDIATE
);

CREATE INDEX shifts_schedule_date_idx
    ON shifts (schedule_id, work_date, employee_id);

CREATE INDEX shifts_source_template_idx
    ON shifts (schedule_id, source_template_id)
    WHERE source_template_id IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS shifts;
SQL);
    }
};
