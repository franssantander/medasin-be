<?php

namespace App\Http\Controllers\Focus;

use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\StartFocusSessionRequest;
use App\Http\Requests\Focus\StoreFocusReflectionRequest;
use App\Http\Resources\Focus\FocusSessionResource;
use App\Models\FocusSession;
use App\Services\Focus\FocusService;
use Illuminate\Http\Request;

class FocusSessionController extends Controller
{
    public function __construct(private readonly FocusService $focus) {}

    public function store(StartFocusSessionRequest $request)
    {
        return $this->sessionResponse($request, $this->focus->startSession($request->user(), $request->validated()), 'Focus session started.', 201);
    }

    public function pause(Request $request, FocusSession $focusSession)
    {
        return $this->sessionResponse($request, $this->focus->pause($request->user(), $focusSession), 'Focus session paused.');
    }

    public function resume(Request $request, FocusSession $focusSession)
    {
        return $this->sessionResponse($request, $this->focus->resume($request->user(), $focusSession), 'Focus session resumed.');
    }

    public function complete(Request $request, FocusSession $focusSession)
    {
        return $this->sessionResponse($request, $this->focus->complete($request->user(), $focusSession), 'Focus session completed.');
    }

    public function cancel(Request $request, FocusSession $focusSession)
    {
        return $this->sessionResponse($request, $this->focus->cancel($request->user(), $focusSession), 'Focus session reset.');
    }

    public function reflection(StoreFocusReflectionRequest $request, FocusSession $focusSession)
    {
        return $this->sessionResponse($request, $this->focus->reflect($request->user(), $focusSession, $request->validated()), 'Reflection saved.');
    }

    private function sessionResponse(Request $request, FocusSession $session, string $message, int $status = 200)
    {
        return $this->success(FocusSessionResource::make($session)->resolve($request), $message, $status);
    }
}
