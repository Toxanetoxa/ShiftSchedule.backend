<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE employees (
    id              uuid PRIMARY KEY,
    full_name       text NOT NULL,
    position_title  text NOT NULL DEFAULT 'Сотрудник',
    avatar_url      text,
    is_active       boolean NOT NULL DEFAULT true,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT employees_name_valid
        CHECK (char_length(btrim(full_name)) BETWEEN 1 AND 200),
    CONSTRAINT employees_position_valid
        CHECK (char_length(btrim(position_title)) BETWEEN 1 AND 160)
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS employees;
SQL);
    }
};
