<?php

use App\Models\Habit;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

function standaloneHabit(array $input = []): Habit
{
    $uuid = test()->postJson(route('habits.store'), ['name' => 'Run', ...$input])->assertCreated()->json('data.uuid');

    return Habit::where('uuid', $uuid)->sole();
}

it('validates standalone habit frequency and schedule like area habits', function (array $input, string $field): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('habits.store'), ['name' => 'Run', ...$input])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown frequency' => [['frequency' => 'hourly'], 'frequency'],
    'weekly without days' => [['frequency' => 'weekly'], 'schedule.days'],
    'monthly without dates' => [['frequency' => 'monthly', 'schedule' => ['dates' => []]], 'schedule.dates'],
    'unknown weekday' => [['frequency' => 'weekly', 'schedule' => ['days' => ['funday']]], 'schedule.days.0'],
]);

it('keeps the icon and description sent for a standalone habit', function (): void {
    Passport::actingAs(User::factory()->create());

    $habit = standaloneHabit(['icon' => 'BookOpen', 'description' => 'Twenty pages']);

    expect($habit)->icon->toBe('BookOpen')->description->toBe('Twenty pages');
});

it('moves a standalone habit between areas and rejects archived areas', function (): void {
    $user = User::factory()->create();
    $health = $user->areas()->create(['name' => 'Health']);
    $archived = $user->areas()->create(['name' => 'Old', 'archived_at' => now()]);
    Passport::actingAs($user);
    $habit = standaloneHabit();

    $this->putJson(route('habits.update', $habit), ['area_uuid' => $health->uuid])
        ->assertOk()
        ->assertJsonPath('data.area.uuid', $health->uuid);
    $this->putJson(route('habits.update', $habit), ['area_uuid' => $archived->uuid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('area_uuid');
    $this->putJson(route('habits.update', $habit), ['area_uuid' => null])->assertOk();

    expect($habit->fresh()->area_id)->toBeNull();
});

it('moves a deleted standalone habit to Trash so it can be restored', function (): void {
    Passport::actingAs(User::factory()->create());
    $habit = standaloneHabit();

    $this->deleteJson(route('habits.destroy', $habit))->assertOk();
    $entry = TrashEntry::query()->where('subject_type', Habit::class)->where('subject_id', $habit->id)->sole();
    $this->postJson(route('trash.restore', $entry->uuid))->assertOk();

    expect($habit->fresh()->trashed())->toBeFalse();
});

it('rejects standalone check-ins that are unscheduled, before the habit existed, or not real dates', function (string $date, string $field): void {
    $this->travelTo('2026-10-08T04:00:00Z');
    Passport::actingAs(User::factory()->create());
    $habit = standaloneHabit(['frequency' => 'weekly', 'schedule' => ['days' => ['monday', 'thursday']]]);
    $habit->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

    $this->putJson(route('habits.check-ins.update', [$habit, 'date' => $date]), ['completed' => true, 'timezone' => 'UTC'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('habit_check_ins', 0);
})->with([
    'unscheduled Tuesday' => ['2026-10-06', 'date'],
    'before creation' => ['2026-09-28', 'date'],
    'rolled-over date' => ['2026-02-31', 'date'],
]);

it('accepts a scheduled standalone check-in', function (): void {
    $this->travelTo('2026-10-08T04:00:00Z');
    Passport::actingAs(User::factory()->create());
    $habit = standaloneHabit(['frequency' => 'weekly', 'schedule' => ['days' => ['monday', 'thursday']]]);
    $habit->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

    $this->putJson(route('habits.check-ins.update', [$habit, 'date' => '2026-10-05']), ['completed' => true, 'timezone' => 'UTC'])
        ->assertOk()
        ->assertJsonPath('data.date', '2026-10-05');
});
