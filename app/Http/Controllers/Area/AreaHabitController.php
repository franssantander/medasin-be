<?php

namespace App\Http\Controllers\Area;

use App\Data\Area\HabitData;
use App\Http\Controllers\Area\Concerns\InteractsWithOwnedAreas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Area\StoreHabitRequest;
use App\Http\Requests\Area\UpdateHabitRequest;
use App\Models\Area;
use App\Models\Habit;
use App\Services\Trash\TrashService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AreaHabitController extends Controller
{
    use InteractsWithOwnedAreas;

    public function __construct(private readonly TrashService $trashService) {}

    public function index(Request $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->habits()->latest()->paginate(15));
    }

    public function store(StoreHabitRequest $request, Area $area)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $data = HabitData::from($request->validated())->toArray();
        $this->validateSchedule($data);
        $habit = $area->habits()->create($data);

        return $this->success($habit, 'Successfully created habit.', 201);
    }

    public function show(Request $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);

        return $this->success($area->habits()->whereKey($habit->getKey())->firstOrFail());
    }

    public function update(UpdateHabitRequest $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $habit = $area->habits()->whereKey($habit->getKey())->firstOrFail();
        $data = HabitData::from($request->validated())->toArray();
        $this->validateSchedule($data, $habit);
        $habit->update($data);

        return $this->success($habit->fresh(), 'Successfully updated habit.');
    }

    public function destroy(Request $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $habit = $area->habits()->whereKey($habit->getKey())->firstOrFail();
        $this->trashService->delete($request->user(), $habit, 'habit', $habit->name, $area->name);

        return $this->success(null, 'Habit moved to Trash. It will be permanently deleted after 30 days.');
    }

    public function history(Request $request, Area $area, Habit $habit)
    {
        $area = $this->ownedArea($request->user(), $area);
        $habit = $area->habits()->whereKey($habit->getKey())->firstOrFail();
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]);
        $timezone = $validated['timezone'] ?? 'UTC';
        $start = CarbonImmutable::parse($validated['start_date'], $timezone)->startOfDay();
        $end = CarbonImmutable::parse($validated['end_date'], $timezone)->startOfDay();
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['end_date' => 'History is limited to 366 days.']);
        }

        return $this->success($this->historyData($habit, $start, $end, $timezone));
    }

    public function checkIn(Request $request, Area $area, Habit $habit, string $date)
    {
        $area = $this->ownedArea($request->user(), $area);
        $this->ensureAreaIsMutable($area);
        $habit = $area->habits()->whereKey($habit->getKey())->firstOrFail();
        $validated = $request->validate([
            'completed' => ['required', 'boolean'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]);
        $timezone = $validated['timezone'] ?? 'UTC';
        $checkInDate = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
        if ($checkInDate->isFuture()) {
            throw ValidationException::withMessages(['date' => 'Future check-ins are not allowed.']);
        }
        if ($checkInDate->lt(CarbonImmutable::parse($habit->created_at)->setTimezone($timezone)->startOfDay())) {
            throw ValidationException::withMessages(['date' => 'Check-ins cannot be recorded before the habit was created.']);
        }
        if (! $habit->is_active) {
            throw ValidationException::withMessages(['habit' => 'Paused habits cannot be checked in.']);
        }
        if (! $this->isScheduled($habit, $checkInDate, $timezone)) {
            throw ValidationException::withMessages(['date' => 'This habit is not scheduled on that date.']);
        }
        $existing = $habit->checkIns()->whereDate('check_in_date', $checkInDate)->first();
        if ($existing) {
            $existing->update(['completed' => $validated['completed']]);
        } else {
            $habit->checkIns()->create([
                'check_in_date' => $checkInDate,
                'completed' => $validated['completed'],
            ]);
        }

        return $this->success(
            $this->historyData(
                $habit,
                CarbonImmutable::now($timezone)->subDays(89)->startOfDay(),
                CarbonImmutable::now($timezone)->startOfDay(),
                $timezone,
            ),
            $validated['completed'] ? 'Habit completed.' : 'Habit marked as missed.',
        );
    }

    private function historyData(Habit $habit, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $rangeCheckIns = $habit->checkIns()->whereBetween('check_in_date', [$start, $end])->orderBy('check_in_date')->get();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $allCheckIns = $habit->checkIns()->whereDate('check_in_date', '<=', $today->toDateString())->get()->keyBy(fn ($item) => $item->check_in_date->toDateString());
        $cursor = CarbonImmutable::parse($habit->created_at)->setTimezone($timezone)->startOfDay();
        $current = 0;
        $best = 0;
        while ($cursor->lte($today)) {
            if ($this->isScheduled($habit, $cursor, $timezone)) {
                $entry = $allCheckIns->get($cursor->toDateString());
                if ($entry?->completed) {
                    $current++;
                    $best = max($best, $current);
                } elseif ($cursor->lt($today) || $entry) {
                    $current = 0;
                }
            }
            $cursor = $cursor->addDay();
        }
        $scheduled = 0;
        $completed = 0;
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            if ($this->isScheduled($habit, $cursor, $timezone)) {
                $scheduled++;
                if ($allCheckIns->get($cursor->toDateString())?->completed) {
                    $completed++;
                }
            }
        }

        return [
            'check_ins' => $rangeCheckIns->map(fn ($item) => ['date' => $item->check_in_date->toDateString(), 'completed' => $item->completed])->values(),
            'current_streak' => $current,
            'best_streak' => $best,
            'scheduled_count' => $scheduled,
            'completed_count' => $completed,
            'completion_rate' => $scheduled ? (int) round(($completed / $scheduled) * 100) : 0,
        ];
    }

    private function isScheduled(Habit $habit, CarbonImmutable $date, string $timezone): bool
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

    private function validateSchedule(array $data, ?Habit $habit = null): void
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
}
