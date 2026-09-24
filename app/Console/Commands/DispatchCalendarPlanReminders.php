<?php

namespace App\Console\Commands;

use App\Jobs\Calendar\DeliverCalendarPlanReminder;
use App\Jobs\Calendar\SendCalendarPlanReminderEmail;
use App\Models\CalendarPlan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('calendar:dispatch-reminders')]
#[Description('Queue due calendar plan notifications and reminder emails')]
class DispatchCalendarPlanReminders extends Command
{
    public function handle(): int
    {
        CalendarPlan::query()
            ->whereNotNull('reminder_token')
            ->where('remind_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('notified_at')->orWhereNull('email_sent_at'))
            ->orderBy('id')
            ->eachById(function (CalendarPlan $plan): void {
                if (! $plan->notified_at) {
                    DeliverCalendarPlanReminder::dispatch($plan->getKey(), $plan->reminder_token);
                } elseif (! $plan->email_sent_at) {
                    SendCalendarPlanReminderEmail::dispatch($plan->getKey(), $plan->reminder_token);
                }
            });

        return self::SUCCESS;
    }
}
