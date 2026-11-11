<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\ScheduleEmployee;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
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
            'employee_id' => fn (array $attributes): string => ScheduleEmployee::factory()->create([
                'schedule_id' => $attributes['schedule_id'],
            ])->employee_id,
            'work_date' => '2026-11-09',
            'starts_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse(
                CarbonImmutable::parse($attributes['work_date'])->toDateString().' 07:00',
                Schedule::query()->findOrFail($attributes['schedule_id'])->time_zone,
            )->utc(),
            'ends_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse($attributes['starts_at'])->addHours(8),
            'source_template_id' => null,
            'label_snapshot' => 'Своя смена',
            'color_key_snapshot' => 'custom',
        ];
    }

    public function forMember(ScheduleEmployee $membership): static
    {
        return $this->state(fn (): array => [
            'schedule_id' => $membership->schedule_id,
            'employee_id' => $membership->employee_id,
        ]);
    }
}
