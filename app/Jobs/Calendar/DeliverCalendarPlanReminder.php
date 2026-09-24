<?php

namespace App\Jobs\Calendar;

use App\Events\CalendarPlanReminderDelivered;
use App\Models\CalendarPlan;
use App\Notifications\CalendarPlanReminder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverCalendarPlanReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $planId, public readonly string $reminderToken) {}

    public function uniqueId(): string
    {
        return 'calendar-plan-deliver-'.$this->reminderToken;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $plan = CalendarPlan::query()->with('user')->lockForUpdate()->find($this->planId);
            if (! $plan || $plan->reminder_token !== $this->reminderToken || ! $plan->remind_at
                || $plan->remind_at->isFuture() || $plan->notified_at || ! $plan->user) {
                return;
            }

            $notification = new CalendarPlanReminder(
                $plan->uuid,
                $plan->title,
                $plan->event_date->toDateString(),
                $plan->is_all_day ? null : $plan->starts_at->copy()->setTimezone($plan->timezone)->format('H:i'),
                $plan->timezone,
            );
            $notice = $plan->user->notifications()->firstOrCreate(
                ['id' => $this->reminderToken],
                ['type' => CalendarPlanReminder::class, 'data' => $notification->toArray($plan->user)],
            );
            $plan->forceFill(['notified_at' => now()])->save();

            if ($notice->wasRecentlyCreated) {
                CalendarPlanReminderDelivered::dispatch((int) $plan->user->getKey(), (string) $notice->getKey());
            }
        });
    }
}
