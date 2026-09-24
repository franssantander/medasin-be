<?php

namespace App\Jobs\Calendar;

use App\Models\CalendarPlan;
use App\Notifications\CalendarPlanReminder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SendCalendarPlanReminderEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $planId, public readonly string $reminderToken) {}

    public function uniqueId(): string
    {
        return 'calendar-plan-email-'.$this->reminderToken;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $plan = CalendarPlan::query()->with('user')->lockForUpdate()->find($this->planId);
            if (! $plan || $plan->reminder_token !== $this->reminderToken || ! $plan->notified_at
                || $plan->email_sent_at || ! $plan->user) {
                return;
            }

            Notification::sendNow($plan->user, new CalendarPlanReminder(
                $plan->uuid,
                $plan->title,
                $plan->event_date->toDateString(),
                $plan->is_all_day ? null : $plan->starts_at->copy()->setTimezone($plan->timezone)->format('H:i'),
                $plan->timezone,
            ), ['mail']);
            $plan->forceFill(['email_sent_at' => now()])->save();
        });
    }
}
