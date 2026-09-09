<?php

namespace App\Services\Habit;

use App\Models\Area;
use App\Models\Habit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class HabitService
{
    /**
     * Create a new class instance.
     */
    public function owned(User $user, Habit $habit): Habit
    {
        return $user->habits()->whereKey($habit)->firstOrFail();
    }

    public function create(User $user, array $data): Habit
    {
        $areaUuid = $data['area_uuid'] ?? null;
        unset($data['area_uuid']);
        $habit = $user->habits()->create($data);
        if ($areaUuid) {
            $habit->area()->associate($user->areas()->where('uuid', $areaUuid)->whereNull('archived_at')->firstOrFail());
            $habit->save();
        }

        return $habit->load('area');
    }

    public function linkToArea(User $user, Area $area, string $habitUuid): Habit
    {
        $habit = $user->habits()->where('uuid', $habitUuid)->firstOrFail();
        $habit->area()->associate($area);
        $habit->save();

        return $habit->fresh()->load('area');
    }

    public function checkIn(User $user, Habit $habit, string $date, array $data): array
    {
        $habit = $this->owned($user, $habit);
        $day = CarbonImmutable::parse($date, $data['timezone'] ?? 'UTC')->startOfDay();
        if ($day->isFuture() || ! $habit->is_active) {
            throw ValidationException::withMessages(['date' => 'This habit cannot be checked in on that date.']);
        } $entry = $habit->checkIns()->updateOrCreate(['check_in_date' => $day], ['completed' => $data['completed']]);

        return ['date' => $entry->check_in_date->toDateString(), 'completed' => $entry->completed];
    }
}
