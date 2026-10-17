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

        $violation = $this->increaseViolations($user, [$feature], $increment)[$feature->value] ?? null;
        if ($violation !== null) {
            throw $violation;
        }
    }

    /**
     * @param  list<CoreFeature>  $features
     * @return array<string, PlanLimitExceededException>
     */
    public function increaseViolations(User $user, array $features, int $increment = 1): array
    {
        if ($increment < 1) {
            throw new InvalidArgumentException('Quota increments must be positive.');
        }
        if ($features === [] || ! config('plans.enforcement_enabled')) {
            return [];
        }

        $limits = $this->entitlements->resolve($user)['limits'];
        $violations = [];
        foreach ($features as $feature) {
            $limit = $limits[$feature->value];
            if ($limit === null) {
                continue;
            }

            $usage = $user->{$feature->value}()->count();
            if ($usage + $increment > $limit) {
                $violations[$feature->value] = new PlanLimitExceededException($feature->value, $usage, $limit);
            }
        }

        return $violations;
    }

    /** Call after taking the user-row lock in the upload's persistence transaction, before writing any file. */
    public function assertCanStore(User $user, int $bytes): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Quota checks require an account transaction.');
        }
        if ($bytes < 1 || ! config('plans.enforcement_enabled')) {
            return;
        }

        $limit = $this->entitlements->resolve($user)['limits']['storage_bytes'];
        if ($limit === null) {
            return;
        }

        $usage = $this->storageUsage($user);
        if ($usage + $bytes > $limit) {
            throw PlanLimitExceededException::forStorage($usage, $limit);
        }
    }

    /**
     * Bytes of uploaded files the account holds. Trashed files still count because they
     * stay on disk until purge; area backgrounds and profile images are not counted.
     */
    public function storageUsage(User $user): int
    {
        $attachments = DB::table('resource_attachments')
            ->join('resources', 'resources.id', '=', 'resource_attachments.resource_id')
            ->where('resources.user_id', $user->getKey())
            ->sum('resource_attachments.size');
        $notes = DB::table('note_media')
            ->join('notes', 'notes.id', '=', 'note_media.note_id')
            ->where('notes.user_id', $user->getKey())
            ->sum('note_media.size');
        $letters = DB::table('letter_media')
            ->join('letters', 'letters.id', '=', 'letter_media.letter_id')
            ->where('letters.user_id', $user->getKey())
            ->sum('letter_media.size');

        return (int) $attachments + (int) $notes + (int) $letters;
    }

    /** @return array{projects: int, areas: int, resources: int, storage_bytes: int} */
    public function usage(User $user): array
    {
        $usage = [];
        foreach (CoreFeature::cases() as $feature) {
            $usage[$feature->value] = $user->{$feature->value}()->count();
        }
        $usage['storage_bytes'] = $this->storageUsage($user);

        return $usage;
    }
}
