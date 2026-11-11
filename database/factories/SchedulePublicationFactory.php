<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\SchedulePublication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulePublication>
 */
class SchedulePublicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => Schedule::factory(),
            'from_date' => '2026-11-09',
            'to_date' => fn (array $attributes): string => CarbonImmutable::parse($attributes['from_date'])->addWeek()->toDateString(),
            'source_version' => fn (array $attributes): int => Schedule::query()->findOrFail($attributes['schedule_id'])->version,
            'snapshot_schema_version' => 1,
            'snapshot' => function (array $attributes): array {
                $schedule = Schedule::query()->findOrFail($attributes['schedule_id']);
                $coverage = [];
                for ($date = CarbonImmutable::parse($attributes['from_date']); $date->lt(CarbonImmutable::parse($attributes['to_date'])); $date = $date->addDay()) {
                    $coverage[] = ['date' => $date->toDateString(), 'shiftCount' => 0, 'covered' => false];
                }

                return [
                    'schemaVersion' => 1,
                    'schedule' => ['id' => $schedule->id, 'name' => $schedule->name, 'timeZone' => $schedule->time_zone, 'version' => $attributes['source_version']],
                    'range' => ['from' => $attributes['from_date'], 'to' => $attributes['to_date']],
                    'employees' => [],
                    'shifts' => [],
                    'coverage' => $coverage,
                ];
            },
            'created_by' => 'demo-manager',
        ];
    }
}
