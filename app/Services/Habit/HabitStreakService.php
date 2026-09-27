<?php

namespace App\Services\Habit;

use App\Models\Habit;
use App\Models\HabitCheckIn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HabitStreakService
{
    public function longestCurrentStreak(User $user, string $timezone = 'UTC'): int
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $longest = 0;

        $habits = $user->habits()
            ->where('is_active', true)
            ->select(['id', 'frequency', 'schedule', 'created_at'])
            ->with(['checkIns' => fn ($query) => $query
                ->select(['id', 'habit_id', 'check_in_date', 'completed'])
                ->whereDate('check_in_date', '<=', $today->toDateString()),
            ])
            ->lazy(100);

        foreach ($habits as $habit) {
            $checkInsByDate = $habit->checkIns->keyBy(fn (HabitCheckIn $checkIn): string => $checkIn->check_in_date->toDateString());
            $longest = max($longest, $this->streaks($habit, $checkInsByDate, $today, $timezone)['current']);
        }

        return $longest;
    }

    /**
     * @param  Collection<string, HabitCheckIn>  $checkInsByDate
     * @return array{current: int, best: int}
     */
    public function streaks(Habit $habit, Collection $checkInsByDate, CarbonImmutable $today, string $timezone): array
    {
        $cursor = CarbonImmutable::parse($habit->created_at)->setTimezone($timezone)->startOfDay();
        $current = 0;
        $best = 0;

        while ($cursor->lte($today)) {
            if ($this->isScheduled($habit, $cursor, $timezone)) {
                $entry = $checkInsByDate->get($cursor->toDateString());
                if ($entry?->completed) {
                    $current++;
                    $best = max($best, $current);
                } elseif ($cursor->lt($today) || $entry) {
                    $current = 0;
                }
            }

            $cursor = $cursor->addDay();
        }

        return ['current' => $current, 'best' => $best];
    }

    public function isScheduled(Habit $habit, CarbonImmutable $date, string $timezone): bool
    {
        $frequency = $habit->frequency->value;
        if ($frequency === 'daily') {
            return true;
        }

        $schedule = $habit->schedule ?? [];
        if ($frequency === 'monthly') {
            return in_array($date->day, $schedule['dates'] ?? [$habit->created_at->copy()->setTimezone($timezone)->day], true);
        }

        return in_array(strtolower($date->englishDayOfWeek), $schedule['days'] ?? [strtolower($habit->created_at->copy()->setTimezone($timezone)->englishDayOfWeek)], true);
    }
}
