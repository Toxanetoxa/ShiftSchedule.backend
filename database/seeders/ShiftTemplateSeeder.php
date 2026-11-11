<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShiftTemplateSeeder extends Seeder
{
    public const MORNING_ID = '00000000-0000-4000-8000-000000000101';

    public const DAY_ID = '00000000-0000-4000-8000-000000000102';

    public const EVENING_ID = '00000000-0000-4000-8000-000000000103';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now('UTC')->toIso8601String();
        $templates = [
            ['id' => self::MORNING_ID, 'name' => 'Утро', 'color_key' => 'morning', 'start_time' => '07:00', 'end_time' => '15:00'],
            ['id' => self::DAY_ID, 'name' => 'День', 'color_key' => 'day', 'start_time' => '10:00', 'end_time' => '18:00'],
            ['id' => self::EVENING_ID, 'name' => 'Вечер', 'color_key' => 'evening', 'start_time' => '15:00', 'end_time' => '23:00'],
        ];
        foreach ($templates as $sortOrder => $template) {
            DB::table('shift_templates')->insertOrIgnore([
                ...$template,
                'schedule_id' => ScheduleSeeder::SCHEDULE_ID,
                'end_day_offset' => 0,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }
}
