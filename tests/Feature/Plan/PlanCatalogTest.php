<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('publishes seeded plan prices and limits to guests', function (): void {
    $this->seed(PlanSeeder::class);

    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.slug', 'free')->assertJsonPath('data.0.price', 0)
        ->assertJsonPath('data.0.limits.projects', 10)
        ->assertJsonPath('data.0.limits.areas', 5)
        ->assertJsonPath('data.0.limits.resources', 100)
        ->assertJsonPath('data.0.limits.kanban_boards', null)
        ->assertJsonPath('data.1.slug', 'focus')->assertJsonPath('data.1.price', 99)
        ->assertJsonPath('data.1.limits.projects', 50)
        ->assertJsonPath('data.1.limits.areas', 20)
        ->assertJsonPath('data.1.limits.resources', 1000)
        ->assertJsonPath('data.2.slug', 'clarity')->assertJsonPath('data.2.price', 149)
        ->assertJsonPath('data.2.limits.kanban_boards', null)
        ->assertJsonPath('data.2.limits.projects', null)
        ->assertJsonPath('data.2.limits.areas', null)
        ->assertJsonPath('data.2.limits.resources', null)
        ->assertJsonPath('data.0.deprecated_limits', [
            'journal_entries', 'notes', 'attachments_mb', 'reminders',
            'pomodoro', 'kanban_boards', 'kanban_tasks',
        ]);

    $this->assertDatabaseCount('plans', 3);
});

it('omits a deleted plan from the public catalog', function (): void {
    $active = Plan::factory()->create(['name' => 'Available']);
    $deleted = Plan::factory()->create(['name' => 'Retired']);
    $deleted->deleteOrFail();

    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $active->uuid)->assertJsonMissing(['name' => 'Retired']);

    $this->assertSoftDeleted($deleted);
});
