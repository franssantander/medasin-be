<?php

namespace App\Services\Plan;

use App\Enum\CoreFeature;
use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class PlanEntitlementService
{
    /**
     * @return array{plan: array{slug: string, name: string}, grant_type: string, expires_at: ?string, enforcement_enabled: bool, limits: array{projects: ?int, areas: ?int, resources: ?int}}
     */
    public function resolve(User $user): array
    {
        $now = now();
        $assignment = $user->planAssignments()
            ->where('status', PlanAssignmentStatus::ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query): void {
                    $query->where('grant_type', PlanGrantType::FREE)
                        ->whereNull('ends_at')
                        ->whereHas('plan', fn (Builder $plan): Builder => $plan->where('slug', 'free'));
                })->orWhere(function (Builder $query) use ($now): void {
                    $query->where('grant_type', PlanGrantType::RECURRING)
                        ->where('ends_at', '>', $now)
                        ->whereHas('plan', fn (Builder $plan): Builder => $plan->where('slug', '!=', 'free'));
                });
            })
            ->with('plan')
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->first();
        $plan = $assignment ? $assignment->plan : Plan::withTrashed()->where('slug', 'free')->first();
        $enabled = (bool) config('plans.enforcement_enabled');

        if ($assignment && ! $plan) {
            throw new RuntimeException('The assigned plan definition is unavailable.');
        }

        $limits = ! $enabled
            ? array_fill_keys(array_column(CoreFeature::cases(), 'value'), null)
            : $this->limitsFor($plan);

        return [
            'plan' => ['slug' => $plan?->slug ?? 'free', 'name' => $plan?->name ?? 'Free'],
            'grant_type' => $assignment?->grant_type->value ?? PlanGrantType::FREE->value,
            'expires_at' => $assignment?->ends_at?->toISOString(),
            'enforcement_enabled' => $enabled,
            'limits' => $limits,
        ];
    }

    /** @return array{projects: ?int, areas: ?int, resources: ?int} */
    public function limitsFor(?Plan $plan): array
    {
        $definition = $plan ? $plan->limits : config('plans.limits.free');
        $limits = [];

        foreach (CoreFeature::cases() as $feature) {
            if (! is_array($definition) || ! array_key_exists($feature->value, $definition)) {
                throw new RuntimeException('A required Core plan limit is missing.');
            }

            $limit = $definition[$feature->value];
            if ($limit !== null && (! is_int($limit) || $limit < 0)) {
                throw new RuntimeException('Core plan limits must be non-negative integers or null.');
            }
            $limits[$feature->value] = $limit;
        }

        return $limits;
    }
}
