<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('keeps goal completion and reopening consistent across area reads', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $areaUuid = $this->postJson(route('area.store'), ['name' => 'Learning'])->assertCreated()->json('data.uuid');
    $area = $user->areas()->where('uuid', $areaUuid)->sole();
    $goalUuid = $this->postJson(route('area.goals.store', $area), ['title' => 'Finish a course'])
        ->assertCreated()->json('data.uuid');
    $goal = $area->goals()->where('uuid', $goalUuid)->sole();

    $this->putJson(route('area.goals.update', [$area, $goal]), ['status' => 'completed'])
        ->assertOk()->assertJsonPath('data.completed_at', '2026-10-05T08:00:00.000000Z');
    $this->getJson(route('area.goals.index', [$area, 'filter' => 'completed']))
        ->assertOk()->assertJsonPath('data.counts.completed', 1)
        ->assertJsonPath('data.items.data.0.uuid', $goalUuid);
    $this->putJson(route('area.goals.update', [$area, $goal]), ['status' => 'in_progress'])
        ->assertOk()->assertJsonPath('data.completed_at', null);
    $this->getJson(route('area.goals.index', [$area, 'filter' => 'active']))
        ->assertOk()->assertJsonPath('data.counts.completed', 0)
        ->assertJsonPath('data.items.data.0.status', 'in_progress');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id, 'area_id' => $area->id, 'status' => 'in_progress', 'completed_at' => null,
    ]);
});

it('returns 404 without modifying a goal supplied under a different area', function (): void {
    $user = User::factory()->create();
    $first = $user->areas()->create(['name' => 'First']);
    $second = $user->areas()->create(['name' => 'Second']);
    $goal = $first->goals()->create(['title' => 'Keep private', 'status' => 'pending']);
    Passport::actingAs($user);

    $this->putJson(route('area.goals.update', [$second, $goal]), ['status' => 'completed'])->assertNotFound();

    $this->assertDatabaseHas('goals', ['id' => $goal->id, 'status' => 'pending', 'completed_at' => null]);
});
