<?php

namespace Database\Seeders;

use App\Enum\Currency;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'A simple space to organize your thoughts, projects, and daily life.',
                'currency' => Currency::Peso,
                'price' => 0,
                'is_active' => true,
                'limits' => config('plans.limits.free'),
            ],
            [
                'name' => 'Focus',
                'slug' => 'focus',
                'description' => 'For deeper focus, better organization, and a more connected personal system.',
                'currency' => Currency::Peso,
                // TODO: confirm actual price with product before launch
                'price' => 99,
                'is_active' => true,
                'limits' => config('plans.limits.focus'),
            ],
            [
                'name' => 'Clarity',
                'slug' => 'clarity',
                'description' => 'For those who want the full Medasin experience with advanced tools, insights, and flexibility.',
                'currency' => Currency::Peso,
                // TODO: confirm actual price with product before launch
                'price' => 149,
                'is_active' => true,
                'limits' => config('plans.limits.clarity'),
            ],
        ];

        DB::transaction(function () use ($plans): void {
            foreach ($plans as $plan) {
                $definition = Plan::withTrashed()->firstOrNew(['slug' => $plan['slug']]);
                if ($definition->exists) {
                    unset($plan['is_active']);
                }
                $plan['limits'] = [
                    ...$plan['limits'],
                    ...array_fill_keys(config('plans.deprecated_limits'), null),
                ];
                $definition->fill($plan)->save();
            }
        });
    }
}
