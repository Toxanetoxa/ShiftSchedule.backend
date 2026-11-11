<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScheduleEmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (array_keys(EmployeeSeeder::EMPLOYEES) as $sortOrder => $employeeId) {
            DB::table('schedule_employees')->insertOrIgnore([
                'schedule_id' => ScheduleSeeder::SCHEDULE_ID,
                'employee_id' => $employeeId,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
        }
    }
}
