<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Sync the catalog with config/plans.php, the single source of tier definitions.
     * Existing rows are overwritten except for their retirement state.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (config('plans.tiers') as $slug => $tier) {
                $definition = Plan::withTrashed()->firstOrNew(['slug' => $slug]);
                $plan = [
                    ...$tier,
                    'slug' => $slug,
                    'limits' => [
                        ...$tier['limits'],
                        ...array_fill_keys(config('plans.deprecated_limits'), null),
                    ],
                ];
                if (! $definition->exists) {
                    $plan['is_active'] = true;
                }
                $definition->fill($plan)->save();
            }
        });
    }
}
