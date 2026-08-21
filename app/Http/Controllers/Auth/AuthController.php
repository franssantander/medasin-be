<?php

namespace App\Http\Controllers\Auth;

use App\Data\Auth\UserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    public function __construct(
        protected TokenService $tokenService
    ) {}

    public function login(LoginRequest $request)
    {
        if (!Auth::attempt($request->validated())) {
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

    public function me(Request $request)
    {
        $userData = UserData::from($request->user());
        return $this->success($userData, 'User profile retrieved successfully.');
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $token = $user->token();

            if ($token) {
                $this->tokenService->revokeForAccessToken($token->id);
                $token->revoke();
            }
        }

        [$forgetAccessCookie, $forgetRefreshCookie] = $this->tokenService->forgetCookies();

        return $this->success(null, 'Logout successful.')
            ->withCookie($forgetAccessCookie)
            ->withCookie($forgetRefreshCookie);
    }

    public function refresh(Request $request)
    {
        $plainRefreshToken = $request->cookie('refresh_token');

        if (!$plainRefreshToken) {
            return $this->error(null, 'Refresh token missing.', Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->tokenService->rotate($plainRefreshToken);

        if (!$result) {
            return $this->error(null, 'Refresh token is invalid or has expired.', Response::HTTP_UNAUTHORIZED);
        }

        [$user, $accessCookie, $refreshCookie] = $result;

        return $this->success(null, 'Token refreshed successfully.')
            ->withCookie($accessCookie)
            ->withCookie($refreshCookie);
    }
}
