<?php

namespace App\Http\Controllers\Plan;

use App\Data\Plan\PlanData;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->cached($request, fn (): JsonResponse => $this->success(
            Plan::query()->where('is_active', true)->orderBy('price_minor')->orderBy('id')->get()
                ->map(fn (Plan $plan): array => PlanData::fromModel($plan)->toArray())->all(),
        ));
    }
}
