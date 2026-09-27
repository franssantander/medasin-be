<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowDashboardRequest;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function show(ShowDashboardRequest $request): JsonResponse
    {
        return $this->success($this->dashboard->show(
            $request->user(),
            $request->validated('timezone', 'UTC'),
        ));
    }
}
