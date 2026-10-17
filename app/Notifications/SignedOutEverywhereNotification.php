<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when an old sign-in token is replayed, which means a copy of it was taken and every session was revoked. */
class SignedOutEverywhereNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    public function __construct()
    {
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
        return (new MailMessage)
            ->subject('We signed you out of '.config('app.name').' everywhere')
            ->line('Someone tried to reuse an expired sign-in token from your account, so we signed out every device to protect it.')
            ->line('Sign in again to continue. If you did not expect this, change your password.');
    }
}
