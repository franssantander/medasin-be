<?php

namespace App\Http\Controllers\Auth;

use App\Data\Auth\UserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Requests\Auth\VerifyPasswordResetRequest;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService,
        protected TokenService $tokenService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return $this->success(
            $this->authService->emailVerificationStatus($user),
            'Registration successful. Please verify your email address.',
            Response::HTTP_CREATED,
        );
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        [$user, $accessCookie, $refreshCookie] = $this->authService->verifyEmail($attributes['email'], $attributes['otp']);

        return $this->success(UserData::from($user), 'Email verified successfully.')
            ->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $this->authService->resendVerification($request->validated('email'));

        return $this->success(null, 'If the account needs verification, a verification code has been sent.', Response::HTTP_ACCEPTED);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->validated('email'));

        return $this->success(null, 'If an account exists for this email address, a password reset code has been sent.', Response::HTTP_ACCEPTED);
    }

    public function verifyPasswordReset(VerifyPasswordResetRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $result = $this->authService->verifyPasswordReset($attributes['email'], $attributes['otp']);

        return $this->success($result, 'Password reset code verified successfully.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated());
        [$accessCookie, $refreshCookie] = $this->tokenService->forgetCookies();

        return $this->success(null, 'Password reset successful. Please log in with your new password.')
            ->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $result = $this->authService->login($attributes['username'], $attributes['password']);

        if (isset($result['verification_required'])) {
            $message = 'Please verify your email address before logging in.';

            return response()->json([
                'data' => $result,
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => $message,
                'errors' => ['email' => [$message]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        [$user, $accessCookie, $refreshCookie] = $result;

        return $this->success(UserData::from($user), 'Login successful.')
            ->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(UserData::from($request->user()), 'User profile retrieved successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->token();

        if ($token) {
            $this->tokenService->revokeForAccessToken($user, $token->id);
        }

        [$accessCookie, $refreshCookie] = $this->tokenService->forgetCookies();

        return $this->success(null, 'Logout successful.')
            ->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function refresh(Request $request): JsonResponse
    {
        $plainRefreshToken = $request->cookie('refresh_token');

        if (! is_string($plainRefreshToken) || $plainRefreshToken === '') {
            return $this->error(null, 'Refresh token missing.', Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->tokenService->rotate($plainRefreshToken);

        if (! $result) {
            return $this->error(null, 'Refresh token is invalid or has expired.', Response::HTTP_UNAUTHORIZED);
        }

        [$user, $accessCookie, $refreshCookie] = $result;

        return $this->success(null, 'Token refreshed successfully.')
            ->withCookie($accessCookie)
            ->withCookie($refreshCookie);
    }
}
