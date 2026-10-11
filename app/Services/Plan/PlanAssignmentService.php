<?php

namespace App\Services\Plan;

use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use App\Services\ApiReadCacheService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanAssignmentService
{
    private const LIFETIME_CONVERSION_SOURCE = 'lifetime-conversion';

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
        if ($grantType === PlanGrantType::LIFETIME) {
            throw ValidationException::withMessages(['grant' => 'Lifetime plan grants are not supported. Paid plans require recurring access with a future expiry.']);
        }

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
                    PlanGrantType::LIFETIME => false,
                };
                if (! $validGrant) {
                    throw ValidationException::withMessages(['grant' => 'Free has no expiry and recurring paid access requires a future expiry.']);
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

    public function convertLifetime(): int
    {
        $converted = 0;
        User::query()->whereHas('planAssignments', fn (Builder $query): Builder => $query
            ->where('grant_type', PlanGrantType::LIFETIME)
            ->where('status', PlanAssignmentStatus::ACTIVE))
            ->eachById(function (User $user) use (&$converted): void {
                $changed = DB::transaction(function () use ($user): bool {
                    $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                    if ($user->planAssignments()->where('source', self::LIFETIME_CONVERSION_SOURCE)
                        ->where('source_reference', $user->uuid)->exists()) {
                        return false;
                    }

                    $startsAt = CarbonImmutable::now('UTC')->startOfSecond();
                    $assignment = $user->planAssignments()
                        ->where('status', PlanAssignmentStatus::ACTIVE)
                        ->where('starts_at', '<=', $startsAt)
                        ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt))
                        ->with('plan')
                        ->orderByDesc('starts_at')->orderByDesc('id')
                        ->lockForUpdate()->first();
                    if ($assignment?->grant_type !== PlanGrantType::LIFETIME || $assignment->plan?->slug !== 'clarity') {
                        return false;
                    }

                    $this->entitlements->limitsFor($assignment->plan);
                    $assignment->updateOrFail(['status' => PlanAssignmentStatus::SUPERSEDED]);
                    $user->planAssignments()->create([
                        'plan_id' => $assignment->plan_id,
                        'status' => PlanAssignmentStatus::ACTIVE,
                        'grant_type' => PlanGrantType::RECURRING,
                        'starts_at' => $startsAt,
                        'ends_at' => $startsAt->addMonthNoOverflow(),
                        'source' => self::LIFETIME_CONVERSION_SOURCE,
                        'source_reference' => $user->uuid,
                    ]);
                    $this->cache->invalidateUser($user);

                    return true;
                });
                if ($changed) {
                    $converted++;
                }
            });

        return $converted;
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
