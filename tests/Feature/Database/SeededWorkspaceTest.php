<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('exposes seeded workspace content only to its owner while sharing the plan catalog', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
    $demo = User::where('email', 'focus@example.com')->sole();
    Passport::actingAs($demo);

    $this->getJson(route('area.index'))->assertOk()->assertJsonCount(7, 'data');
    $this->getJson(route('project.index'))->assertOk()->assertJsonCount(8, 'data');
    $this->getJson(route('home.show'))->assertOk()->assertJsonPath('data.stats.areas', 7);
    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(3, 'data');

    $other = User::factory()->create();
    Passport::actingAs($other);
    $this->getJson(route('area.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('project.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(3, 'data');

    $this->assertDatabaseCount('oauth_clients', 1);
    $this->assertDatabaseCount('plans', 3);
    $this->assertModelExists($demo);
    $this->assertModelExists($other);
});
