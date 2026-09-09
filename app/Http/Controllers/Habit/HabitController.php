<?php

namespace App\Http\Controllers\Habit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Habit\HabitDashboardRequest;
use App\Http\Requests\Habit\StoreHabitRequest;
use App\Http\Requests\Habit\UpdateHabitCheckInRequest;
use App\Http\Requests\Habit\UpdateHabitRequest;
use App\Models\Habit;
use App\Models\HabitCheckIn;
use App\Services\Habit\HabitService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HabitController extends Controller
{
    public function __construct(private readonly HabitService $habits) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return $this->success($request->user()->habits()->with('area')->latest()->get());
    }

    public function calendar(HabitDashboardRequest $request)
    {
        $validated = $request->validated();
        $timezone = $validated['timezone'] ?? 'UTC';
        $start = CarbonImmutable::parse($validated['start_date'], $timezone)->startOfDay();
        $end = CarbonImmutable::parse($validated['end_date'], $timezone)->startOfDay();

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'end_date' => 'Calendar history is limited to 366 days.',
            ]);
        }

        $habits = $request->user()->habits()->with('area')->latest()->get();
        $checkIns = HabitCheckIn::query()
            ->whereIn('habit_id', $habits->modelKeys())
            ->whereBetween('check_in_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('check_in_date')
            ->get()
            ->groupBy('habit_id');

        return $this->success([
            'habits' => $habits,
            'check_ins' => $habits->mapWithKeys(fn (Habit $habit) => [
                $habit->uuid => $checkIns->get($habit->getKey(), collect())
                    ->map(fn (HabitCheckIn $checkIn) => [
                        'date' => $checkIn->check_in_date->toDateString(),
                        'completed' => $checkIn->completed,
                    ])
                    ->values(),
            ]),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHabitRequest $request)
    {
        return $this->success($this->habits->create($request->user(), $request->validated()), 'Habit created.', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Habit $habit)
    {
        return $this->success($this->habits->owned($request->user(), $habit)->load('area'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateHabitRequest $request, Habit $habit)
    {
        $habit = $this->habits->owned($request->user(), $habit);
        $habit->update(collect($request->validated())->except('area_uuid')->all());

        return $this->success($habit->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Habit $habit)
    {
        $this->habits->owned($request->user(), $habit)->delete();

        return $this->success(null, 'Habit deleted.');
    }

    public function checkIn(UpdateHabitCheckInRequest $request, Habit $habit, string $date)
    {
        return $this->success($this->habits->checkIn($request->user(), $habit, $date, $request->validated()));
    }
}
