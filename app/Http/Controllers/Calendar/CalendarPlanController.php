<?php

namespace App\Http\Controllers\Calendar;

use App\Data\Calendar\CalendarPlanData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\ListCalendarPlanRequest;
use App\Http\Requests\Calendar\StoreCalendarPlanRequest;
use App\Http\Requests\Calendar\UpdateCalendarPlanRequest;
use App\Models\CalendarPlan;
use App\Services\Calendar\CalendarPlanService;
use App\Services\Trash\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarPlanController extends Controller
{
    public function __construct(
        private readonly CalendarPlanService $calendarPlans,
        private readonly TrashService $trash,
    ) {}

    public function index(ListCalendarPlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $plans = $this->calendarPlans->listing($request->user(), $data['start_date'], $data['end_date'], $data['timezone']);

        return $this->success($plans->map(fn (CalendarPlan $plan): array => CalendarPlanData::fromModel($plan)->toArray())->all());
    }

    public function upcoming(Request $request): JsonResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'timezone'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $plans = $this->calendarPlans->upcoming($request->user(), $data['timezone'], $data['limit'] ?? 10);

        return $this->success($plans->map(fn (CalendarPlan $plan): array => CalendarPlanData::fromModel($plan)->toArray())->all());
    }

    public function store(StoreCalendarPlanRequest $request): JsonResponse
    {
        $plan = $this->calendarPlans->create($request->user(), $request->validated());

        return $this->success(CalendarPlanData::fromModel($plan)->toArray(), 'Calendar plan created.', 201);
    }

    public function show(Request $request, CalendarPlan $calendarPlan): JsonResponse
    {
        $plan = $this->calendarPlans->owned($request->user(), $calendarPlan)->load(['project', 'area']);

        return $this->success(CalendarPlanData::fromModel($plan)->toArray());
    }

    public function update(UpdateCalendarPlanRequest $request, CalendarPlan $calendarPlan): JsonResponse
    {
        $plan = $this->calendarPlans->owned($request->user(), $calendarPlan);
        $plan = $this->calendarPlans->update($request->user(), $plan, $request->validated());

        return $this->success(CalendarPlanData::fromModel($plan)->toArray(), 'Calendar plan updated.');
    }

    public function destroy(Request $request, CalendarPlan $calendarPlan): JsonResponse
    {
        $plan = $this->calendarPlans->owned($request->user(), $calendarPlan);
        $this->trash->delete($request->user(), $plan, 'calendar_plan', $plan->title, 'Calendar');

        return $this->success(null, 'Calendar plan moved to Trash. It will be permanently deleted after 30 days.');
    }
}
