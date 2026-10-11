<?php

namespace Database\Factories;

use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanAssignment>
 */
class PlanAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => PlanAssignmentStatus::ACTIVE,
            'grant_type' => PlanGrantType::RECURRING,
            'starts_at' => now()->startOfSecond(),
            'ends_at' => now()->addMonthNoOverflow()->startOfSecond(),
            'source' => 'admin',
            'source_reference' => fake()->uuid(),
        ];
    }
}
