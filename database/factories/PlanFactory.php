<?php

namespace Database\Factories;

use App\Enum\Currency;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'currency' => Currency::Peso,
            'price' => fake()->numberBetween(0, 5000),
            'is_active' => true,
            'limits' => [
                ...config('plans.limits.free'),
                ...array_fill_keys(config('plans.deprecated_limits'), null),
            ],
        ];
    }
}
