<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the owner when their account deletion is scheduled, cancelled by signing in, or completed. */
class AccountDeletionNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const SCHEDULED = 'scheduled';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    public function __construct(
        public readonly string $stage,
        public readonly ?CarbonInterface $deletesAt = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        return match ($this->stage) {
            self::SCHEDULED => (new MailMessage)
                ->subject("Your {$app} account is scheduled for deletion")
                ->line("We received a request to delete your {$app} account and all of its data.")
                ->line('It will be permanently deleted on '.$this->deletesAt?->toDayDateTimeString().' (UTC).')
                ->line('To keep your account, sign in before then. Signing in cancels the deletion.')
                ->line('If you did not request this, sign in now and change your password.'),
            self::CANCELLED => (new MailMessage)
                ->subject("Your {$app} account deletion was cancelled")
                ->line('You signed in, so your account and its data will not be deleted.')
                ->line('If this was not you, change your password now.'),
            default => (new MailMessage)
                ->subject("Your {$app} account has been deleted")
                ->line("Your {$app} account and all of its data have been permanently deleted.")
                ->line('This cannot be undone. You are welcome to create a new account at any time.'),
        };
    }
}
