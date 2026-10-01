<?php

namespace App\Notifications;

use App\Enum\AuthOtpPurpose;
use App\Models\AuthOtp;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthOtpNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    public function __construct(
        public readonly int $challengeId,
        public readonly string $version,
        public readonly string $code,
        public readonly AuthOtpPurpose $purpose,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== 'mail' || ! $notifiable instanceof User) {
            return false;
        }

        return AuthOtp::query()
            ->whereKey($this->challengeId)
            ->where('user_id', $notifiable->getKey())
            ->where('version', $this->version)
            ->where('purpose', $this->purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('failed_attempts', '<', 5)
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->purpose === AuthOtpPurpose::EMAIL_VERIFICATION) {
            return (new MailMessage)
                ->subject('Verify your email address')
                ->view([
                    'html' => 'mail.auth.verify-email',
                    'text' => 'mail.auth.verify-email-text',
                ], [
                    'code' => $this->code,
                    'expiresInMinutes' => $this->purpose->expiresInMinutes(),
                ]);
        }

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Password reset requested')
            ->line('Use this code to verify your password reset request.')
            ->line($this->code)
            ->line('This code expires '.$this->purpose->expiresInMinutes().' minutes after it was requested and can only be used once.')
            ->line('If you did not request this code, you can ignore this email.');
    }
}
