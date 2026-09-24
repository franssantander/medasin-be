<?php

namespace Database\Factories;

use App\Models\CalendarPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarPlan>
 */
class CalendarPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now('Asia/Manila')->addDay()->setTime(9, 0);

        return [
            'title' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'event_date' => $startsAt->toDateString(),
            'timezone' => 'Asia/Manila',
            'starts_at' => $startsAt->utc(),
            'is_all_day' => false,
            'reminder_offset_minutes' => null,
            'remind_at' => null,
            'reminder_token' => null,
        ];
    }
}
