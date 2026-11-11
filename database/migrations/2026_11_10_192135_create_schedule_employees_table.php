<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE schedule_employees (
    schedule_id uuid NOT NULL,
    employee_id uuid NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0,
    is_active   boolean NOT NULL DEFAULT true,

    PRIMARY KEY (schedule_id, employee_id),
    CONSTRAINT schedule_employees_schedule_fk
        FOREIGN KEY (schedule_id) REFERENCES schedules (id) ON DELETE RESTRICT,
    CONSTRAINT schedule_employees_employee_fk
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE RESTRICT,
    CONSTRAINT schedule_employees_sort_order_nonnegative CHECK (sort_order >= 0)
);

CREATE INDEX schedule_employees_employee_idx
    ON schedule_employees (employee_id, schedule_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS schedule_employees;
SQL);
    }
};
