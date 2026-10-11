<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('blocks Note writes after its read allowance is exhausted and accepts them after renewal', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    Passport::actingAs($user);
    for ($request = 0; $request < 300; $request++) {
        $this->getJson(route('notes.index'))->assertOk();
    }
    $payload = ['title' => 'After renewal', 'content' => 'Saved once'];

    $this->postJson(route('notes.store'), $payload)->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After', '60');
    $this->assertDatabaseEmpty('notes');
    $this->travel(61)->seconds();
    $this->postJson(route('notes.store'), $payload)->assertCreated()
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '299');

    $this->assertDatabaseCount('notes', 1);
    $this->assertDatabaseHas('notes', ['user_id' => $user->id, 'title' => 'After renewal', 'content' => 'Saved once']);
});
