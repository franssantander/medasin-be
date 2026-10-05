<?php

use App\Models\CalendarPlan;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('reschedules a reminder and restores the updated calendar plan from trash', function (): void {
    $this->travelTo('2026-10-05T00:00:00Z');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $payload = [
        'title' => 'Planning', 'date' => '2026-10-06', 'time' => '10:00',
        'timezone' => 'Asia/Manila', 'is_all_day' => false, 'reminder_offset_minutes' => 60,
    ];
    $uuid = $this->postJson(route('calendar.plans.store'), $payload)->assertCreated()->json('data.uuid');
    $plan = CalendarPlan::where('uuid', $uuid)->sole();

    $this->putJson(route('calendar.plans.update', $plan), array_replace($payload, ['date' => '2026-10-07', 'time' => '14:00']))
        ->assertOk()->assertJsonPath('data.starts_at', '2026-10-07T06:00:00.000000Z')
        ->assertJsonPath('data.remind_at', '2026-10-07T05:00:00.000000Z');
    $this->deleteJson(route('calendar.plans.destroy', $plan))->assertOk();
    $this->getJson(route('calendar.plans.upcoming', ['timezone' => 'Asia/Manila']))->assertOk()->assertJsonCount(0, 'data');
    $entry = TrashEntry::where('subject_uuid', $uuid)->sole();
    $this->postJson(route('trash.restore', $entry))->assertOk();
    $this->getJson(route('calendar.plans.upcoming', ['timezone' => 'Asia/Manila']))->assertOk()
        ->assertJsonPath('data.0.uuid', $uuid)->assertJsonPath('data.0.date', '2026-10-07');

    $this->assertDatabaseHas('calendar_plans', [
        'id' => $plan->id, 'starts_at' => '2026-10-07 06:00:00', 'remind_at' => '2026-10-07 05:00:00', 'deleted_at' => null,
    ]);
    $this->assertModelMissing($entry);
});
