<?php

namespace Tests\Feature\Habit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class HabitTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_returns_owned_habits_and_check_ins_for_the_requested_range(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $habit = $owner->habits()->create([
            'name' => 'Read',
            'frequency' => 'daily',
        ]);
        $other->habits()->create([
            'name' => 'Private habit',
            'frequency' => 'daily',
        ]);
        $habit->checkIns()->create([
            'check_in_date' => '2026-09-08',
            'completed' => true,
        ]);
        Passport::actingAs($owner);

        $this->getJson(route('habits.calendar', [
            'start_date' => '2026-09-06',
            'end_date' => '2026-09-12',
            'timezone' => 'Asia/Manila',
        ]))
            ->assertOk()
            ->assertJsonPath('data.habits.0.uuid', $habit->uuid)
            ->assertJsonPath('data.check_ins.'.$habit->uuid.'.0.date', '2026-09-08')
            ->assertJsonPath('data.check_ins.'.$habit->uuid.'.0.completed', true)
            ->assertJsonPath('data.start_date', '2026-09-06')
            ->assertJsonPath('data.end_date', '2026-09-12')
            ->assertJsonMissing(['name' => 'Private habit']);
    }

    public function test_calendar_rejects_ranges_longer_than_a_year(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('habits.calendar', [
            'start_date' => '2025-01-01',
            'end_date' => '2026-09-12',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }
}
