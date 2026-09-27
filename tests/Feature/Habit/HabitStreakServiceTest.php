<?php

namespace Tests\Feature\Habit;

use App\Models\User;
use App\Services\Habit\HabitStreakService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HabitStreakServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_longest_current_streak_uses_only_active_owned_non_deleted_habits(): void
    {
        $this->travelTo(Carbon::parse('2026-09-07 10:00:00'));
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $schedule = ['days' => ['monday', 'wednesday']];
        $active = $user->habits()->create(['name' => 'Exercise', 'frequency' => 'weekly', 'schedule' => $schedule]);
        $paused = $user->habits()->create(['name' => 'Paused', 'frequency' => 'weekly', 'schedule' => $schedule, 'is_active' => false]);
        $deleted = $user->habits()->create(['name' => 'Deleted', 'frequency' => 'weekly', 'schedule' => $schedule]);
        $other = $otherUser->habits()->create(['name' => 'Private', 'frequency' => 'weekly', 'schedule' => $schedule]);

        $this->travelTo(Carbon::parse('2026-09-16 10:00:00'));
        foreach (['2026-09-07', '2026-09-09', '2026-09-14'] as $date) {
            $active->checkIns()->create(['check_in_date' => $date, 'completed' => true]);
        }
        foreach ([$paused, $deleted, $other] as $habit) {
            foreach (['2026-09-07', '2026-09-09', '2026-09-14', '2026-09-16'] as $date) {
                $habit->checkIns()->create(['check_in_date' => $date, 'completed' => true]);
            }
        }
        $deleted->delete();

        $streakService = app(HabitStreakService::class);
        $this->assertSame(3, $streakService->longestCurrentStreak($user));

        $active->checkIns()->create(['check_in_date' => '2026-09-16', 'completed' => false]);
        $this->assertSame(0, $streakService->longestCurrentStreak($user));
    }

    public function test_current_streak_uses_the_requested_calendar_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 16:30:00', 'UTC'));
        $user = User::factory()->create();
        $habit = $user->habits()->create([
            'name' => 'Monday review',
            'frequency' => 'weekly',
            'schedule' => ['days' => ['monday']],
        ]);
        $habit->checkIns()->create(['check_in_date' => '2026-09-07', 'completed' => true]);

        $streakService = app(HabitStreakService::class);

        $this->assertSame(1, $streakService->longestCurrentStreak($user, 'Asia/Manila'));
        $this->assertSame(0, $streakService->longestCurrentStreak($user, 'UTC'));
    }
}
