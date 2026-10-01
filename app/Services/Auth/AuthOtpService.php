<?php

namespace App\Services\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\AuthOtp;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthOtpService
{
    public function send(User $user, AuthOtpPurpose $purpose): bool
    {
        return DB::transaction(function () use ($user, $purpose): bool {
            $user = User::query()->lockForUpdate()->find($user->id);

            if (! $user || ($purpose === AuthOtpPurpose::EMAIL_VERIFICATION && $user->hasVerifiedEmail())) {
                return false;
            }

            $challenge = AuthOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if ($challenge && $challenge->sent_at->gt(now()->subSeconds(60))) {
                return false;
            }

            do {
                $code = sprintf('%06d', random_int(0, 999999));
            } while ($challenge && Hash::check($code, $challenge->code_hash));
            $challenge = AuthOtp::query()->updateOrCreate([
                'user_id' => $user->id,
                'purpose' => $purpose,
            ], [
                'code_hash' => Hash::make($code),
                'version' => (string) Str::uuid(),
                'expires_at' => now()->addMinutes($purpose->expiresInMinutes()),
                'failed_attempts' => 0,
                'sent_at' => now(),
                'consumed_at' => null,
            ]);

            if ($purpose === AuthOtpPurpose::PASSWORD_RESET) {
                Password::broker()->deleteToken($user);
            }

            $user->notify(new AuthOtpNotification($challenge->id, $challenge->version, $code, $purpose));

            return true;
        });
    }

    /**
     * @return array{otp_expires_in: int, resend_after: int}
     */
    public function status(User $user, AuthOtpPurpose $purpose): array
    {
        $challenge = AuthOtp::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->first();

        if (! $challenge) {
            return ['otp_expires_in' => 0, 'resend_after' => 0];
        }

        $active = ! $challenge->consumed_at
            && $challenge->failed_attempts < 5
            && $challenge->expires_at->gt(now());

        return [
            'otp_expires_in' => $active ? max(0, (int) ceil(now()->diffInSeconds($challenge->expires_at))) : 0,
            'resend_after' => max(0, (int) ceil(now()->diffInSeconds($challenge->sent_at->copy()->addSeconds(60)))),
        ];
    }

    public function consume(User $user, AuthOtpPurpose $purpose, #[\SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $purpose, $code): bool {
            $user = User::query()->lockForUpdate()->find($user->id);

            if (! $user) {
                return false;
            }

            $challenge = AuthOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->consumed_at || $challenge->expires_at->lte(now()) || $challenge->failed_attempts >= 5) {
                return false;
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('failed_attempts');

                return false;
            }

            $challenge->update(['consumed_at' => now()]);

            return true;
        });
    }
}
