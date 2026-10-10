<?php

namespace App\Services\Plan;

use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use App\Services\ApiReadCacheService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanAssignmentService
{
    public function __construct(
        private readonly ApiReadCacheService $cache,
        private readonly PlanEntitlementService $entitlements,
    ) {}

    public function assign(
        User $user,
        Plan $plan,
        PlanGrantType $grantType,
        ?CarbonImmutable $expiresAt,
        string $reference,
        string $source = 'admin',
    ): PlanAssignment {
        Validator::make(['reference' => $reference, 'source' => $source], [
            'reference' => ['required', 'string', 'max:120'],
            'source' => ['required', 'string', 'max:64'],
        ])->validate();
        $expiresAt = $expiresAt?->utc()->startOfSecond();

        try {
            return DB::transaction(function () use ($user, $plan, $grantType, $expiresAt, $reference, $source): PlanAssignment {
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $existing = PlanAssignment::query()->where('source', $source)->where('source_reference', $reference)->first();

                if ($existing) {
                    $sameExpiry = $expiresAt === null
                        ? $existing->ends_at === null
                        : $existing->ends_at?->equalTo($expiresAt) === true;
                    if ($existing->user_id !== $user->getKey() || $existing->plan_id !== $plan->getKey()
                        || $existing->grant_type !== $grantType || ! $sameExpiry) {
                        throw ValidationException::withMessages(['reference' => 'This reference already identifies a different plan grant.']);
                    }

                    return $existing;
                }

                $plan = Plan::query()->whereKey($plan->getKey())->where('is_active', true)->first();
                if (! $plan || ! array_key_exists($plan->slug, config('plans.limits'))) {
                    throw ValidationException::withMessages(['plan' => 'Choose an available product plan.']);
                }

                $validGrant = match ($grantType) {
                    PlanGrantType::FREE => $plan->slug === 'free' && $expiresAt === null,
                    PlanGrantType::RECURRING => $plan->slug !== 'free' && $expiresAt?->isFuture() === true,
                    PlanGrantType::LIFETIME => $plan->slug === 'clarity' && $expiresAt === null,
                };
                if (! $validGrant) {
                    throw ValidationException::withMessages(['grant' => 'Free has no expiry, recurring paid access requires a future expiry, and lifetime access requires Clarity without an expiry.']);
                }
                $this->entitlements->limitsFor($plan);

                $user->planAssignments()->where('status', PlanAssignmentStatus::ACTIVE)
                    ->update(['status' => PlanAssignmentStatus::SUPERSEDED->value]);
                $assignment = $user->planAssignments()->create([
                    'plan_id' => $plan->getKey(),
                    'status' => PlanAssignmentStatus::ACTIVE,
                    'grant_type' => $grantType,
                    'starts_at' => now()->startOfSecond(),
                    'ends_at' => $expiresAt,
                    'source' => $source,
                    'source_reference' => $reference,
                ]);
                $this->cache->invalidateUser($user);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'This reference already identifies a plan grant.']);
        }
    }

    public function revoke(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $changed = $user->planAssignments()->where('status', PlanAssignmentStatus::ACTIVE)
                ->update(['status' => PlanAssignmentStatus::REVOKED->value]);
            if ($changed > 0) {
                $this->cache->invalidateUser($user);
            }

            return $changed;
        });
    }

    public function backfillFree(): int
    {
        $free = Plan::query()->where('slug', 'free')->where('is_active', true)->firstOrFail();
        $created = 0;
        User::query()->eachById(function (User $user) use ($free, &$created): void {
            DB::transaction(function () use ($user, $free, &$created): void {
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                if ($user->planAssignments()->exists()) {
                    return;
                }

                $this->assign($user, $free, PlanGrantType::FREE, null, $user->uuid, 'backfill');
                $created++;
            });
        });

        return $created;
    }
}
