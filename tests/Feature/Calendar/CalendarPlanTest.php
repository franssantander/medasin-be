<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarPlan;
use App\Models\TrashEntry;
use App\Models\User;
use Database\Seeders\CalendarPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CalendarPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_requires_authentication_and_pricing_route_remains_available(): void
    {
        $this->getJson(route('calendar.plans.index'))->assertUnauthorized();
        $this->postJson(route('calendar.plans.store'))->assertUnauthorized();
        $this->getJson(route('notifications.index'))->assertUnauthorized();
        $this->getJson(route('plan.index'))->assertOk();
    }

    public function test_timed_plan_stores_utc_and_returns_local_fields(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Launch']);
        Passport::actingAs($user);
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));

        $response = $this->postJson(route('calendar.plans.store'), [
            'title' => 'Meet the team', 'date' => '2026-09-26', 'time' => '14:30',
            'timezone' => 'Asia/Manila', 'is_all_day' => false,
            'project_uuid' => $project->uuid, 'reminder_offset_minutes' => 180,
        ])->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-09-26T06:30:00.000000Z')
            ->assertJsonPath('data.remind_at', '2026-09-26T03:30:00.000000Z')
            ->assertJsonPath('data.time', '14:30')
            ->assertJsonPath('data.project.uuid', $project->uuid)
            ->assertJsonPath('data.reminder_status', 'scheduled');

        $this->assertDatabaseHas('calendar_plans', [
            'uuid' => $response->json('data.uuid'), 'user_id' => $user->getKey(),
            'starts_at' => '2026-09-26 06:30:00',
        ]);
    }

    public function test_all_day_reminder_uses_nine_am_and_local_date_stays_fixed(): void
    {
        Passport::actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));

        $this->postJson(route('calendar.plans.store'), [
            'title' => 'Conference', 'date' => '2026-09-26',
            'timezone' => 'Asia/Manila', 'is_all_day' => true,
            'reminder_offset_minutes' => 1440,
        ])->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-09-25T16:00:00.000000Z')
            ->assertJsonPath('data.remind_at', '2026-09-25T01:00:00.000000Z')
            ->assertJsonPath('data.date', '2026-09-26')
            ->assertJsonPath('data.time', null);

        $this->getJson(route('calendar.plans.index', [
            'start_date' => '2026-09-26', 'end_date' => '2026-09-26', 'timezone' => 'UTC',
        ]))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reminder_offset_presets_custom_and_no_reminder(): void
    {
        Passport::actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));

        foreach ([0 => '2026-09-26T01:00:00.000000Z', 45 => '2026-09-26T00:15:00.000000Z',
            180 => '2026-09-25T22:00:00.000000Z', 1440 => '2026-09-25T01:00:00.000000Z'] as $offset => $expected) {
            $this->postJson(route('calendar.plans.store'), [
                'title' => 'Reminder', 'date' => '2026-09-26', 'time' => '09:00',
                'timezone' => 'Asia/Manila', 'is_all_day' => false,
                'reminder_offset_minutes' => $offset,
            ])->assertCreated()->assertJsonPath('data.remind_at', $expected);
        }

        $this->postJson(route('calendar.plans.store'), [
            'title' => 'Quiet', 'date' => '2026-09-26', 'time' => '09:00',
            'timezone' => 'Asia/Manila', 'is_all_day' => false,
        ])->assertCreated()->assertJsonPath('data.reminder_status', 'none');
    }

    public function test_listing_and_upcoming_are_owned_and_follow_local_boundaries(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));
        $early = CalendarPlan::factory()->for($owner)->create([
            'title' => 'Early', 'event_date' => '2026-09-25', 'starts_at' => '2026-09-24 16:30:00',
        ]);
        CalendarPlan::factory()->for($owner)->create([
            'title' => 'Outside', 'event_date' => '2026-09-26', 'starts_at' => '2026-09-25 16:30:00',
        ]);
        CalendarPlan::factory()->for($other)->create([
            'title' => 'Private', 'event_date' => '2026-09-25', 'starts_at' => '2026-09-24 17:00:00',
        ]);
        Passport::actingAs($owner);

        $this->getJson(route('calendar.plans.index', [
            'start_date' => '2026-09-25', 'end_date' => '2026-09-25', 'timezone' => 'Asia/Manila',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $early->uuid);

        $this->getJson(route('calendar.plans.upcoming', ['timezone' => 'Asia/Manila']))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.uuid', $early->uuid);
    }

    public function test_calendar_rejects_ranges_longer_than_62_days(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('calendar.plans.index', [
            'start_date' => '2026-09-01', 'end_date' => '2026-12-01', 'timezone' => 'Asia/Manila',
        ]))->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_validation_rejects_foreign_links_both_links_and_dst_gap(): void
    {
        $user = User::factory()->create();
        $foreignProject = User::factory()->create()->projects()->create(['name' => 'Private']);
        $area = $user->areas()->create(['name' => 'Work']);
        Passport::actingAs($user);
        $base = [
            'title' => 'Planning', 'date' => '2026-09-26', 'time' => '09:00',
            'timezone' => 'Asia/Manila', 'is_all_day' => false,
        ];

        $this->postJson(route('calendar.plans.store'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'date', 'time', 'timezone', 'is_all_day']);
        $this->postJson(route('calendar.plans.store'), $base + ['project_uuid' => $foreignProject->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('project_uuid');
        $this->postJson(route('calendar.plans.store'), $base + [
            'project_uuid' => $foreignProject->uuid, 'area_uuid' => $area->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors(['project_uuid', 'area_uuid']);
        $this->postJson(route('calendar.plans.store'), [
            'title' => 'Missing hour', 'date' => '2026-03-08', 'time' => '02:30',
            'timezone' => 'America/New_York', 'is_all_day' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('calendar_plans', 0);
    }

    public function test_updates_reschedule_only_when_schedule_changes_and_skip_past_alerts(): void
    {
        Passport::actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));
        $data = [
            'title' => 'Original', 'date' => '2026-09-26', 'time' => '09:00',
            'timezone' => 'Asia/Manila', 'is_all_day' => false, 'reminder_offset_minutes' => 180,
        ];
        $uuid = $this->postJson(route('calendar.plans.store'), $data)->json('data.uuid');
        $token = CalendarPlan::query()->where('uuid', $uuid)->firstOrFail()->reminder_token;

        $this->putJson(route('calendar.plans.update', $uuid), array_replace($data, ['title' => 'Renamed']))->assertOk();
        $this->assertSame($token, CalendarPlan::query()->where('uuid', $uuid)->firstOrFail()->reminder_token);
        $this->putJson(route('calendar.plans.update', $uuid), array_replace($data, ['date' => '2026-09-27']))->assertOk();
        $this->assertNotSame($token, CalendarPlan::query()->where('uuid', $uuid)->firstOrFail()->reminder_token);
        $this->putJson(route('calendar.plans.update', $uuid), array_replace($data, [
            'date' => '2026-09-24', 'time' => '10:00',
        ]))->assertOk()->assertJsonPath('data.reminder_status', 'skipped');
    }

    public function test_delete_uses_trash_and_foreign_plans_are_hidden(): void
    {
        $owner = User::factory()->create();
        $plan = CalendarPlan::factory()->for($owner)->create();
        Passport::actingAs(User::factory()->create());
        $this->getJson(route('calendar.plans.show', $plan))->assertNotFound();
        $this->putJson(route('calendar.plans.update', $plan), [
            'title' => 'Private', 'date' => '2026-09-26', 'time' => '09:00',
            'timezone' => 'Asia/Manila', 'is_all_day' => false,
        ])->assertNotFound();
        $this->deleteJson(route('calendar.plans.destroy', $plan))->assertNotFound();

        Passport::actingAs($owner);
        $this->deleteJson(route('calendar.plans.destroy', $plan))->assertOk();
        $this->assertSoftDeleted('calendar_plans', ['id' => $plan->getKey()]);
        $entry = TrashEntry::query()->where('subject_type', CalendarPlan::class)->sole();
        $this->postJson(route('trash.restore', $entry))->assertOk();
        $this->assertNotSoftDeleted('calendar_plans', ['id' => $plan->getKey()]);
    }

    public function test_restoring_after_reminder_time_does_not_send_late_alert(): void
    {
        $owner = User::factory()->create();
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));
        $plan = CalendarPlan::factory()->for($owner)->create([
            'reminder_offset_minutes' => 0,
            'remind_at' => now()->addHour(),
            'reminder_token' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
        ]);
        Passport::actingAs($owner);
        $this->deleteJson(route('calendar.plans.destroy', $plan))->assertOk();
        $this->travel(2)->hours();
        $entry = TrashEntry::query()->where('subject_type', CalendarPlan::class)->sole();

        $this->postJson(route('trash.restore', $entry))->assertOk();

        $this->assertNull($plan->fresh()->remind_at);
        $this->assertNull($plan->fresh()->reminder_token);
    }

    public function test_demo_seeder_is_opt_in_and_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->seed(CalendarPlanSeeder::class);
        $this->seed(CalendarPlanSeeder::class);

        $this->assertSame(1, $user->calendarPlans()->count());
    }
}
