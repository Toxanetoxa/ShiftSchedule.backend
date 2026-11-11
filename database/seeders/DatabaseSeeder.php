<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([
                UserSeeder::class,
                ScheduleSeeder::class,
                EmployeeSeeder::class,
                ScheduleEmployeeSeeder::class,
                ShiftTemplateSeeder::class,
                ShiftSeeder::class,
                SchedulePublicationSeeder::class,
            ]);
        });
    }
}
