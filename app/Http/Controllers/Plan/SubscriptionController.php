<?php

namespace App\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\Services\Plan\PlanEntitlementService;
use App\Services\Plan\PlanQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request, PlanEntitlementService $entitlements, PlanQuotaService $quota): JsonResponse
    {
        return $this->success([
            ...$entitlements->resolve($request->user()),
            'usage' => $quota->usage($request->user()),
        ]);
    }
}
