<?php

namespace Database\Seeders;

use App\Models\CalendarPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

class CalendarPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        $start = now('Asia/Manila')->addWeek()->startOfDay();
        if ($user->calendarPlans()->where('title', 'Review the coming week')->exists()) {
            return;
        }

        $plan = new CalendarPlan;
        $plan->user()->associate($user);
        $plan->forceFill([
            'title' => 'Review the coming week',
            'event_date' => $start->toDateString(),
            'timezone' => 'Asia/Manila',
            'starts_at' => $start->utc(),
            'is_all_day' => true,
        ])->save();
    }
}
