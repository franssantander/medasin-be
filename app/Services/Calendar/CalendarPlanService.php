<?php

namespace App\Services\Calendar;

use App\Models\CalendarPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CalendarPlanService
{
    public function owned(User $user, CalendarPlan $calendarPlan): CalendarPlan
    {
        return $user->calendarPlans()->whereKey($calendarPlan->getKey())->firstOrFail();
    }

    /** @return Collection<int, CalendarPlan> */
    public function listing(User $user, string $startDate, string $endDate, string $timezone): Collection
    {
        $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($endDate, $timezone)->addDay()->startOfDay()->utc();
        $endExclusiveDate = CarbonImmutable::parse($endDate)->addDay()->toDateString();

        return $user->calendarPlans()
            ->with(['project', 'area'])
            ->where(function ($query) use ($startDate, $endExclusiveDate, $start, $end): void {
                $query->where(fn ($allDay) => $allDay->where('is_all_day', true)
                    ->where('event_date', '>=', $startDate)
                    ->where('event_date', '<', $endExclusiveDate))
                    ->orWhere(fn ($timed) => $timed->where('is_all_day', false)
                        ->where('starts_at', '>=', $start)
                        ->where('starts_at', '<', $end));
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, CalendarPlan> */
    public function upcoming(User $user, string $timezone, int $limit): Collection
    {
        $today = now($timezone)->toDateString();

        return $user->calendarPlans()
            ->with(['project', 'area'])
            ->where(function ($query) use ($today): void {
                $query->where(fn ($allDay) => $allDay->where('is_all_day', true)->where('event_date', '>=', $today))
                    ->orWhere(fn ($timed) => $timed->where('is_all_day', false)->where('starts_at', '>=', now()));
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): CalendarPlan
    {
        $plan = new CalendarPlan;
        $plan->user()->associate($user);

        return $this->save($user, $plan, $data);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, CalendarPlan $plan, array $data): CalendarPlan
    {
        return $this->save($user, $plan, $data);
    }

    /** @param array<string, mixed> $data */
    private function save(User $user, CalendarPlan $plan, array $data): CalendarPlan
    {
        $timezone = $data['timezone'];
        $date = $data['date'];
        $isAllDay = (bool) $data['is_all_day'];

        if ($isAllDay) {
            $localStart = CarbonImmutable::parse($date, $timezone)->startOfDay();
            if ($localStart->toDateString() !== $date) {
                throw ValidationException::withMessages(['date' => 'This local calendar date does not exist.']);
            }
            $reminderAnchor = $localStart->setTime(9, 0);
        } else {
            $localStart = $this->resolveTimedStart($date, $data['time'], $timezone);
            $reminderAnchor = $localStart;
        }

        $startsAt = $localStart->utc();
        $offset = $data['reminder_offset_minutes'] ?? null;
        $scheduleChanged = ! $plan->exists
            || $plan->event_date?->toDateString() !== $date
            || $plan->starts_at?->toISOString() !== $startsAt->toISOString()
            || $plan->timezone !== $timezone
            || $plan->is_all_day !== $isAllDay
            || $plan->reminder_offset_minutes !== $offset;

        $projectId = isset($data['project_uuid'])
            ? $user->projects()->where('uuid', $data['project_uuid'])->whereNull('archived_at')->firstOrFail()->getKey()
            : null;
        $areaId = isset($data['area_uuid'])
            ? $user->areas()->where('uuid', $data['area_uuid'])->whereNull('archived_at')->firstOrFail()->getKey()
            : null;

        $plan->forceFill([
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'event_date' => $date,
            'timezone' => $timezone,
            'starts_at' => $startsAt,
            'is_all_day' => $isAllDay,
            'project_id' => $projectId,
            'area_id' => $areaId,
            'reminder_offset_minutes' => $offset,
        ]);

        if ($scheduleChanged) {
            $remindAt = $offset === null ? null : $reminderAnchor->subMinutes($offset)->utc();
            $remindAt = $remindAt?->greaterThan(now()) ? $remindAt : null;
            $plan->forceFill([
                'remind_at' => $remindAt,
                'reminder_token' => $remindAt ? (string) Str::uuid() : null,
                'notified_at' => null,
                'email_sent_at' => null,
            ]);
        }

        $plan->save();

        return $plan->load(['project', 'area']);
    }

    private function resolveTimedStart(string $date, string $time, string $timezone): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $local = "{$date} {$time}";
        $naiveTimestamp = CarbonImmutable::parse($local, 'UTC')->getTimestamp();
        $transitions = $zone->getTransitions($naiveTimestamp - 172800, $naiveTimestamp + 172800) ?: [];
        $offsets = array_unique(array_merge(
            [$zone->getOffset(new DateTimeImmutable('@'.$naiveTimestamp))],
            array_column($transitions, 'offset'),
        ));

        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $naiveTimestamp - $offset;
            if ((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i') === $local) {
                $matches[$candidate] = true;
            }
        }

        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['time' => 'This local time is missing or ambiguous in the selected timezone.']);
        }

        return CarbonImmutable::createFromTimestampUTC((int) array_key_first($matches))->setTimezone($zone);
    }
}
