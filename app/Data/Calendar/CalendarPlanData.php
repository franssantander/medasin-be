<?php

namespace App\Data\Calendar;

use App\Models\CalendarPlan;
use Spatie\LaravelData\Data;

class CalendarPlanData extends Data
{
    public function __construct(
        public string $uuid,
        public string $title,
        public ?string $notes,
        public string $date,
        public ?string $time,
        public string $timezone,
        public string $starts_at,
        public bool $is_all_day,
        public ?array $project,
        public ?array $area,
        public ?int $reminder_offset_minutes,
        public ?string $remind_at,
        public ?string $notified_at,
        public ?string $email_sent_at,
        public string $reminder_status,
    ) {}

    public static function fromModel(CalendarPlan $plan): self
    {
        $localStart = $plan->starts_at->copy()->setTimezone($plan->timezone);
        $reminderStatus = match (true) {
            $plan->reminder_offset_minutes === null => 'none',
            $plan->notified_at !== null => 'fired',
            $plan->remind_at === null => 'skipped',
            default => 'scheduled',
        };

        return self::from([
            'uuid' => $plan->uuid,
            'title' => $plan->title,
            'notes' => $plan->notes,
            'date' => $plan->event_date->toDateString(),
            'time' => $plan->is_all_day ? null : $localStart->format('H:i'),
            'timezone' => $plan->timezone,
            'starts_at' => $plan->starts_at->toISOString(),
            'is_all_day' => $plan->is_all_day,
            'project' => $plan->relationLoaded('project') && $plan->project && ! $plan->project->archived_at
                ? ['uuid' => $plan->project->uuid, 'name' => $plan->project->name]
                : null,
            'area' => $plan->relationLoaded('area') && $plan->area && ! $plan->area->archived_at
                ? ['uuid' => $plan->area->uuid, 'name' => $plan->area->name]
                : null,
            'reminder_offset_minutes' => $plan->reminder_offset_minutes,
            'remind_at' => $plan->remind_at?->toISOString(),
            'notified_at' => $plan->notified_at?->toISOString(),
            'email_sent_at' => $plan->email_sent_at?->toISOString(),
            'reminder_status' => $reminderStatus,
        ]);
    }
}
