<?php

namespace App\Services\Plan;

use App\Enum\CoreFeature;
use App\Models\User;
use App\Support\PlanLimitExceededException;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class PlanQuotaService
{
    public function __construct(private readonly PlanEntitlementService $entitlements) {}

    /**
     * @template TResult
     *
     * @param  Closure(User): TResult  $operation
     * @return TResult
     */
    public function transaction(User $user, Closure $operation): mixed
    {
        return DB::transaction(function () use ($user, $operation): mixed {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            return $operation($user);
        });
    }

    /** Call after taking the user-row lock in the operation's persistence transaction. */
    public function assertCanIncrease(User $user, CoreFeature $feature, int $increment = 1): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Quota checks require an account transaction.');
        }
        if ($increment < 1) {
            throw new InvalidArgumentException('Quota increments must be positive.');
        }
        if (! config('plans.enforcement_enabled')) {
            return;
        }

        $limit = $this->entitlements->resolve($user)['limits'][$feature->value];
        if ($limit === null) {
            return;
        }

        $usage = $user->{$feature->value}()->count();
        if ($usage + $increment > $limit) {
            throw new PlanLimitExceededException($feature, $usage, $limit);
        }
    }

    /** @return array{projects: int, areas: int, resources: int} */
    public function usage(User $user): array
    {
        $usage = [];
        foreach (CoreFeature::cases() as $feature) {
            $usage[$feature->value] = $user->{$feature->value}()->count();
        }

        return $usage;
    }
}
