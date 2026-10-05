<?php

use App\Models\CalendarPlan;
use App\Models\JournalEntry;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('finds the saved revision after a feature is renamed', function (
    Closure $createRecord,
    string $method,
    string $name,
    string $type,
    array $payload,
    string $field,
): void {
    $this->travelTo('2026-10-05T00:00:00Z');
    $user = User::factory()->create();
    /** @var Model $record */
    [$record, $parameters] = $createRecord($user);
    Passport::actingAs($user);
    $this->getJson(route('search.index', ['q' => 'PestRevisionBeacon', 'type' => $type]))
        ->assertOk()->assertJsonPath('data.groups', []);

    $this->json($method, route($name, $parameters), $payload)->assertOk();

    $this->getJson(route('search.index', ['q' => 'PestRevisionBeacon', 'type' => $type]))->assertOk()
        ->assertJsonCount(1, 'data.groups')
        ->assertJsonPath('data.groups.0.type', $type)
        ->assertJsonPath('data.groups.0.items.0.id', $record->uuid)
        ->assertJsonPath('data.groups.0.items.0.title', 'PestRevisionBeacon');
    $this->assertDatabaseHas($record->getTable(), ['id' => $record->id, $field => 'PestRevisionBeacon']);
})->with([
    'project' => [
        function (User $user): array {
            $record = $user->projects()->create(['name' => 'Original project']);

            return [$record, [$record]];
        },
        'PUT', 'project.update', 'project', ['name' => 'PestRevisionBeacon'], 'name',
    ],
    'area' => [
        function (User $user): array {
            $record = $user->areas()->create(['name' => 'Original area']);

            return [$record, [$record]];
        },
        'PUT', 'area.update', 'area', ['name' => 'PestRevisionBeacon'], 'name',
    ],
    'resource' => [
        function (User $user): array {
            $record = $user->resources()->create(['title' => 'Original resource']);

            return [$record, [$record]];
        },
        'PATCH', 'resource.update', 'resource', ['title' => 'PestRevisionBeacon'], 'title',
    ],
    'note' => [
        function (User $user): array {
            $record = $user->standaloneNotes()->create(['title' => 'Original note', 'content' => 'Original content']);

            return [$record, [$record]];
        },
        'PATCH', 'notes.update', 'note', ['title' => 'PestRevisionBeacon'], 'title',
    ],
    'journal' => [
        function (User $user): array {
            $record = JournalEntry::factory()->for($user)->create(['title' => 'Original journal']);

            return [$record, [$record]];
        },
        'PATCH', 'journal.update', 'journal', ['title' => 'PestRevisionBeacon'], 'title',
    ],
    'letter' => [
        function (User $user): array {
            $record = Letter::factory()->for($user)->create(['title' => 'Original letter']);

            return [$record, [$record]];
        },
        'PATCH', 'letters.update', 'letter', ['title' => 'PestRevisionBeacon'], 'title',
    ],
    'habit' => [
        function (User $user): array {
            $record = $user->habits()->create(['name' => 'Original habit']);

            return [$record, [$record]];
        },
        'PUT', 'habits.update', 'habit', ['name' => 'PestRevisionBeacon'], 'name',
    ],
    'calendar plan' => [
        function (User $user): array {
            $record = CalendarPlan::factory()->for($user)->create(['title' => 'Original calendar plan']);

            return [$record, [$record]];
        },
        'PUT', 'calendar.plans.update', 'plan', [
            'title' => 'PestRevisionBeacon', 'date' => '2026-10-06',
            'timezone' => 'UTC', 'is_all_day' => true,
        ], 'title',
    ],
    'goal' => [
        function (User $user): array {
            $area = $user->areas()->create(['name' => 'Goals']);
            $record = $area->goals()->create(['title' => 'Original goal']);

            return [$record, [$area, $record]];
        },
        'PUT', 'area.goals.update', 'goal', ['title' => 'PestRevisionBeacon'], 'title',
    ],
]);
