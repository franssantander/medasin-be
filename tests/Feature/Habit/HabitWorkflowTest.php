<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('shares a single check in between the habit calendar and linked area history', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Health']);
    Passport::actingAs($user);
    $uuid = $this->postJson(route('habits.store'), ['name' => 'Walk', 'frequency' => 'daily'])
        ->assertCreated()->json('data.uuid');
    $habit = $user->habits()->where('uuid', $uuid)->sole();
    $this->postJson(route('area.habits.link', $area), ['habit_uuid' => $uuid])->assertOk();

    $this->putJson(route('habits.check-ins.update', [$habit, 'date' => '2026-10-05']), [
        'completed' => true, 'timezone' => 'UTC',
    ])->assertOk();
    $this->getJson(route('habits.calendar', [
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'timezone' => 'UTC',
    ]))->assertOk()->assertJsonPath('data.check_ins.'.$uuid.'.0.completed', true);
    $this->putJson(route('area.habits.check-ins.update', [$area, $habit, 'date' => '2026-10-05']), [
        'completed' => false, 'timezone' => 'UTC',
    ])->assertOk();
    $this->getJson(route('habits.calendar', [
        'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'timezone' => 'UTC',
    ]))->assertOk()->assertJsonPath('data.check_ins.'.$uuid.'.0.completed', false);

    $this->assertDatabaseCount('habit_check_ins', 1);
    $this->assertDatabaseHas('habit_check_ins', ['habit_id' => $habit->id, 'completed' => false]);
    expect($habit->checkIns()->sole()->check_in_date->toDateString())->toBe('2026-10-05');
    $this->assertDatabaseHas('habits', ['id' => $habit->id, 'area_id' => $area->id]);
});

it('includes both calendar boundaries and excludes adjacent check in dates', function (
    string $startDate,
    array $expectedDates,
): void {
    $user = User::factory()->create();
    $habit = $user->habits()->create(['name' => 'Read']);
    foreach (['2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $date) {
        $habit->checkIns()->create(['check_in_date' => $date, 'completed' => true]);
    }
    Passport::actingAs($user);

    $response = $this->getJson(route('habits.calendar', [
        'start_date' => $startDate, 'end_date' => '2026-10-05', 'timezone' => 'UTC',
    ]))->assertOk();

    expect(array_column($response->json('data.check_ins.'.$habit->uuid), 'date'))->toBe($expectedDates);
})->with([
    'single day' => ['2026-10-05', ['2026-10-05']],
    'two days' => ['2026-10-04', ['2026-10-04', '2026-10-05']],
]);
