<?php

namespace App\Http\Controllers\Auth;

use App\Data\Auth\UserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    public function __construct(
        protected TokenService $tokenService
    ) {}

    public function login(LoginRequest $request)
    {
        if (!Auth::attemp($request->validated())) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.']
            ]);
        }

        if (!Auth::user()->hasVerifiedEmail()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before logging in.'],
            ]);
        }

        [$accessCookie, $refreshCookie] = $this->tokenService->issue(Auth::user());

        return $this->success(
            UserData::from(Auth::user()),
            'Login successful.'
        )->withCookie($accessCookie)->withCookie($refreshCookie);
    }
}