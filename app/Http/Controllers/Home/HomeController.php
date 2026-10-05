<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Http\Requests\Home\ShowHomeRequest;
use App\Services\Home\HomeService;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __construct(private readonly HomeService $home) {}

    public function show(ShowHomeRequest $request): JsonResponse
    {
        $timezone = $request->validated('timezone', 'UTC');

        return $this->cached($request, fn (): JsonResponse => $this->success($this->home->show(
            $request->user(),
            $timezone,
        )), ['date' => now($timezone)->toDateString()]);
    }
}
