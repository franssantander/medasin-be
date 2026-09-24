<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CalendarPlanReminder extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $planUuid,
        public readonly string $title,
        public readonly string $date,
        public readonly ?string $time,
        public readonly string $timezone,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Plan reminder: '.$this->title)
            ->greeting('Your plan is coming up')
            ->line($this->title)
            ->line($this->time
                ? "{$this->date} at {$this->time} ({$this->timezone})"
                : "All day on {$this->date} ({$this->timezone})");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'plan_uuid' => $this->planUuid,
            'title' => $this->title,
            'date' => $this->date,
            'time' => $this->time,
            'timezone' => $this->timezone,
        ];
    }
}
