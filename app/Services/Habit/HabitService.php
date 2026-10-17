<?php

namespace App\Services\Habit;

use App\Models\Area;
use App\Models\Habit;
use App\Models\HabitCheckIn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HabitService
{
    public function __construct(private readonly HabitStreakService $streaks) {}

    public function owned(User $user, Habit $habit): Habit
    {
        return $user->habits()->whereKey($habit)->firstOrFail();
    }

    public function create(User $user, array $data): Habit
    {
        $this->validateSchedule($data);
        $areaUuid = $data['area_uuid'] ?? null;
        unset($data['area_uuid']);

        return DB::transaction(function () use ($user, $data, $areaUuid): Habit {
            $habit = $user->habits()->create($data);
            if ($areaUuid) {
                $habit->area()->associate($this->activeArea($user, $areaUuid));
                $habit->save();
            }

            return $habit->load('area');
        });
    }

    public function update(User $user, Habit $habit, array $data): Habit
    {
        $habit = $this->owned($user, $habit);
        $this->validateSchedule($data, $habit);

        return DB::transaction(function () use ($user, $habit, $data): Habit {
            if (array_key_exists('area_uuid', $data)) {
                $data['area_id'] = $data['area_uuid'] ? $this->activeArea($user, $data['area_uuid'])->getKey() : null;
                unset($data['area_uuid']);
            }
            $habit->updateOrFail($data);

            return $habit->fresh()->load('area');
        });
    }

    public function linkToArea(User $user, Area $area, string $habitUuid): Habit
    {
        $habit = $user->habits()->where('uuid', $habitUuid)->firstOrFail();
        $habit->area()->associate($area);
        $habit->saveOrFail();

        return $habit->fresh()->load('area');
    }

    public function checkIn(User $user, Habit $habit, string $date, array $data): array
    {
        $habit = $this->owned($user, $habit);
        $day = $this->checkInDate($habit, $date, $data['timezone'] ?? 'UTC');
        $entry = DB::transaction(fn (): HabitCheckIn => $habit->checkIns()->updateOrCreate(['check_in_date' => $day], ['completed' => $data['completed']]));

        return ['date' => $entry->check_in_date->toDateString(), 'completed' => $entry->completed];
    }

    /** Weekly and custom habits need weekdays, monthly habits need dates; falls back to the stored values on update. */
    public function validateSchedule(array $data, ?Habit $habit = null): void
    {
        $frequency = $data['frequency'] ?? $habit?->frequency->value ?? 'daily';
        $schedule = array_key_exists('schedule', $data) ? $data['schedule'] : $habit?->schedule;
        if ($frequency === 'weekly' && count($schedule['days'] ?? []) < 1) {
            throw ValidationException::withMessages(['schedule.days' => 'Weekly habits require at least one weekday.']);
        }
        if ($frequency === 'custom' && count($schedule['days'] ?? []) < 1) {
            throw ValidationException::withMessages(['schedule.days' => 'Choose at least one weekday.']);
        }
        if ($frequency === 'monthly' && count($schedule['dates'] ?? []) < 1) {
            throw ValidationException::withMessages(['schedule.dates' => 'Choose at least one date.']);
        }
    }

    /** The check-in rules shared by standalone and Area habits; returns the day to record. */
    public function checkInDate(Habit $habit, string $date, string $timezone): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        // createFromFormat rolls invalid dates over (2026-02-31 becomes March 3), so require a round trip.
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'Enter a valid date.']);
        }
        $day = $parsed->startOfDay();
        if ($day->isFuture()) {
            throw ValidationException::withMessages(['date' => 'Future check-ins are not allowed.']);
        }
        if ($day->lt(CarbonImmutable::parse($habit->created_at)->setTimezone($timezone)->startOfDay())) {
            throw ValidationException::withMessages(['date' => 'Check-ins cannot be recorded before the habit was created.']);
        }
        if (! $habit->is_active) {
            throw ValidationException::withMessages(['habit' => 'Paused habits cannot be checked in.']);
        }
        if (! $this->streaks->isScheduled($habit, $day, $timezone)) {
            throw ValidationException::withMessages(['date' => 'This habit is not scheduled on that date.']);
        }

        return $day;
    }

    private function activeArea(User $user, string $areaUuid): Area
    {
        return $user->areas()->where('uuid', $areaUuid)->whereNull('archived_at')->firstOrFail();
    }
}
