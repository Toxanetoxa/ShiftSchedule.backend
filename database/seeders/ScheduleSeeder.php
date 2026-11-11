<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScheduleSeeder extends Seeder
{
    public const SCHEDULE_ID = '00000000-0000-4000-8000-000000000001';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now('UTC')->toIso8601String();
        DB::table('schedules')->insertOrIgnore([
            'id' => self::SCHEDULE_ID,
            'name' => 'График',
            'time_zone' => 'Europe/Moscow',
            'version' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
