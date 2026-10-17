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
    /** Wrong codes allowed per account and purpose in a rolling day, across resends. */
    public const MAX_RECENT_FAILURES = 15;

    /** Codes that can be sent per account and purpose in a rolling hour. */
    public const MAX_SENDS_PER_HOUR = 5;

    /** How long new codes are refused once MAX_RECENT_FAILURES is reached. */
    public const LOCK_MINUTES = 60;

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

            if ($challenge && ($challenge->sent_at->gt(now()->subSeconds(60)) || $this->isLocked($challenge))) {
                return false;
            }

            $recentSends = $this->recentSends($challenge);
            if ($recentSends >= self::MAX_SENDS_PER_HOUR) {
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
                'recent_sends' => $recentSends + 1,
                'recent_sends_started_at' => $recentSends === 0 ? now() : $challenge->recent_sends_started_at,
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
            && $challenge->expires_at->gt(now())
            && ! $this->isLocked($challenge);
        $resendAt = $challenge->sent_at->copy()->addSeconds(60);
        if ($this->isLocked($challenge)) {
            $resendAt = $resendAt->max($challenge->locked_until);
        }
        if ($this->recentSends($challenge) >= self::MAX_SENDS_PER_HOUR) {
            $resendAt = $resendAt->max($challenge->recent_sends_started_at->copy()->addHour());
        }

        return [
            'otp_expires_in' => $active ? max(0, (int) ceil(now()->diffInSeconds($challenge->expires_at))) : 0,
            'resend_after' => max(0, (int) ceil(now()->diffInSeconds($resendAt))),
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

            if (! $challenge || $challenge->consumed_at || $challenge->expires_at->lte(now())
                || $challenge->failed_attempts >= 5 || $this->isLocked($challenge)) {
                return false;
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $this->recordFailure($challenge);

                return false;
            }

            $challenge->update([
                'consumed_at' => now(),
                'recent_failures' => 0,
                'recent_failures_started_at' => null,
            ]);

            return true;
        });
    }

    /**
     * Counts a wrong code against both the current code and the rolling daily allowance; reaching the
     * allowance locks the purpose so that resending cannot hand out fresh guesses indefinitely.
     */
    protected function recordFailure(AuthOtp $challenge): void
    {
        $windowOpen = $challenge->recent_failures_started_at?->gt(now()->subDay()) === true;
        $recentFailures = ($windowOpen ? $challenge->recent_failures : 0) + 1;
        $locks = $recentFailures >= self::MAX_RECENT_FAILURES;

        $challenge->update([
            'failed_attempts' => $challenge->failed_attempts + 1,
            'recent_failures' => $locks ? 0 : $recentFailures,
            'recent_failures_started_at' => $locks ? null : ($windowOpen ? $challenge->recent_failures_started_at : now()),
            'locked_until' => $locks ? now()->addMinutes(self::LOCK_MINUTES) : $challenge->locked_until,
        ]);
    }

    protected function isLocked(AuthOtp $challenge): bool
    {
        return $challenge->locked_until?->isFuture() === true;
    }

    protected function recentSends(?AuthOtp $challenge): int
    {
        return $challenge && $challenge->recent_sends_started_at?->gt(now()->subHour()) === true
            ? $challenge->recent_sends
            : 0;
    }
}
