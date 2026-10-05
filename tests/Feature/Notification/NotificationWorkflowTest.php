<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('marks a notification read idempotently without affecting another account', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $notice = $user->notifications()->create(['id' => Str::uuid()->toString(), 'type' => 'calendar', 'data' => ['title' => 'Planning']]);
    $private = $other->notifications()->create(['id' => Str::uuid()->toString(), 'type' => 'calendar', 'data' => ['title' => 'Private']]);
    Passport::actingAs($user);

    $this->patchJson(route('notifications.read', $notice->id))->assertOk()
        ->assertJsonPath('data.read_at', '2026-10-05T08:00:00.000000Z');
    $this->travel(1)->minute();
    $this->patchJson(route('notifications.read', $notice->id))->assertOk()
        ->assertJsonPath('data.read_at', '2026-10-05T08:00:00.000000Z');
    $this->patchJson(route('notifications.read', $private->id))->assertNotFound();
    $this->getJson(route('notifications.index', ['unread_only' => 1]))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('notifications.index'))->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.data.title', 'Planning');

    $this->assertDatabaseHas('notifications', ['id' => $notice->id, 'read_at' => '2026-10-05 08:00:00']);
    $this->assertDatabaseHas('notifications', ['id' => $private->id, 'read_at' => null]);
});

it('returns 422 for an invalid notification page size', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->getJson(route('notifications.index', ['per_page' => 51]))
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
});
