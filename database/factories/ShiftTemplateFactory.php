<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftTemplate>
 */
class ShiftTemplateFactory extends Factory
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
            'name' => 'Утро',
            'color_key' => 'morning',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'end_day_offset' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function night(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Ночная',
            'color_key' => 'evening',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'end_day_offset' => 1,
        ]);
    }
}
