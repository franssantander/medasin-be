<?php

namespace App\Services\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class AuthService
{
    public function __construct(
        protected AuthOtpService $otpService,
        protected TokenService $tokenService,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, username: string, password: string, password_confirmation: string}  $attributes
     */
    public function register(#[\SensitiveParameter] array $attributes): User
    {
        try {
            return DB::transaction(function () use ($attributes): User {
                $registration = Arr::only($attributes, [
                    'first_name', 'last_name', 'email', 'username', 'password',
                ]);
                $registration['password'] = Hash::make($registration['password']);
                $user = User::query()->create($registration);

                event(new Registered($user));

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $errors = [];

            foreach (['email', 'username'] as $field) {
                if (User::query()->where($field, $attributes[$field])->exists()) {
                    $errors[$field] = ["The {$field} has already been taken."];
                }
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: User, 1: Cookie, 2: Cookie}|array{email: string, verification_required: true, otp_expires_in: int, resend_after: int}
     */
    public function login(string $username, #[\SensitiveParameter] string $password): array
    {
        return DB::transaction(function () use ($username, $password): array {
            $user = User::query()->where('username', $username)->lockForUpdate()->first();

            if (! $user || ! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'username' => ['The provided credentials are incorrect.'],
                ]);
            }

            if (! $user->hasVerifiedEmail()) {
                return $this->emailVerificationStatus($user);
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            return [$user, ...$this->tokenService->issue($user)];
        });
    }

    /**
     * @return array{0: User, 1: Cookie, 2: Cookie}
     */
    public function verifyEmail(string $email, #[\SensitiveParameter] string $code): array
    {
        $result = DB::transaction(function () use ($email, $code): ?array {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user || $user->hasVerifiedEmail() || ! $this->otpService->consume($user, AuthOtpPurpose::EMAIL_VERIFICATION, $code)) {
                return null;
            }

            $user->markEmailAsVerified();
            event(new Verified($user));

            return [$user, ...$this->tokenService->issue($user)];
        });

        if (! $result) {
            throw $this->invalidOtp();
        }

        return $result;
    }

    /**
     * @return array{email: string, verification_required: true, otp_expires_in: int, resend_after: int}
     */
    public function emailVerificationStatus(User $user): array
    {
        return [
            'email' => $user->email,
            'verification_required' => true,
            ...$this->otpService->status($user, AuthOtpPurpose::EMAIL_VERIFICATION),
        ];
    }

    public function resendVerification(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $user->sendEmailVerificationNotification();
        }
    }

    public function forgotPassword(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $this->otpService->send($user, AuthOtpPurpose::PASSWORD_RESET);
        }
    }

    /**
     * @return array{reset_token: string, expires_in: int}
     */
    public function verifyPasswordReset(string $email, #[\SensitiveParameter] string $code): array
    {
        $result = DB::transaction(function () use ($email, $code): ?array {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user || ! $this->otpService->consume($user, AuthOtpPurpose::PASSWORD_RESET, $code)) {
                return null;
            }

            return [
                'reset_token' => Password::broker()->createToken($user),
                'expires_in' => (int) config('auth.passwords.users.expire') * 60,
            ];
        });

        if (! $result) {
            throw $this->invalidOtp();
        }

        return $result;
    }

    /**
     * @param  array{email: string, reset_token: string, password: string, password_confirmation: string}  $attributes
     */
    public function resetPassword(#[\SensitiveParameter] array $attributes): void
    {
        $status = DB::transaction(function () use ($attributes): string {
            $user = User::query()->where('email', $attributes['email'])->lockForUpdate()->first();

            if (! $user) {
                return Password::INVALID_TOKEN;
            }

            return Password::broker()->reset([
                'email' => $user->email,
                'token' => $attributes['reset_token'],
                'password' => $attributes['password'],
                'password_confirmation' => $attributes['password_confirmation'],
            ], function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])
                    ->setRememberToken(Str::random(60));
                $user->save();

                $this->tokenService->revokeAllForUser($user);
                event(new PasswordReset($user));
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'reset_token' => ['The password reset token is invalid or has expired.'],
            ]);
        }
    }

    protected function invalidOtp(): ValidationException
    {
        return ValidationException::withMessages([
            'otp' => ['The verification code is invalid or has expired.'],
        ]);
    }
}
