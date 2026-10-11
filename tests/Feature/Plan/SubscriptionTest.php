<?php

use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('resolves approved Free limits for an account without catalog or assignment records', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()->assertExactJson([
        'data' => [
            'plan' => ['slug' => 'free', 'name' => 'Free'],
            'grant_type' => 'free',
            'expires_at' => null,
            'enforcement_enabled' => true,
            'limits' => ['projects' => 10, 'areas' => 5, 'resources' => 100],
            'usage' => ['projects' => 0, 'areas' => 0, 'resources' => 0],
        ],
        'status' => 200,
        'message' => 'Request was successful.',
    ]);

    $this->assertDatabaseEmpty('plan_assignments');
    $this->assertDatabaseEmpty('plans');
});

it('resolves the assigned tier without exposing assignment history', function (
    string $slug, array $limits,
): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', $slug)->sole();
    PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => $slug === 'free' ? PlanGrantType::FREE : PlanGrantType::RECURRING,
        'ends_at' => $slug === 'free' ? null : now()->addDay(),
    ]);
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', $slug)
        ->assertJsonPath('data.limits', $limits)
        ->assertJsonMissingPath('data.source')
        ->assertJsonMissingPath('data.source_reference')
        ->assertJsonMissingPath('data.assignments');
})->with([
    'Free' => ['free', ['projects' => 10, 'areas' => 5, 'resources' => 100]],
    'Focus' => ['focus', ['projects' => 50, 'areas' => 20, 'resources' => 1000]],
    'Clarity' => ['clarity', ['projects' => null, 'areas' => null, 'resources' => null]],
]);

it('falls back to Free for an ineligible paid assignment', function (string $slug, string $state): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $attributes = match ($state) {
        'revoked' => ['status' => PlanAssignmentStatus::REVOKED],
        'superseded' => ['status' => PlanAssignmentStatus::SUPERSEDED],
        'expired' => ['ends_at' => now()],
        'future' => ['starts_at' => now()->addMinute()],
        'missing expiry' => ['ends_at' => null],
        'free grant' => ['grant_type' => PlanGrantType::FREE, 'ends_at' => null],
    };
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', $slug)->sole())->create($attributes);
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'free')
        ->assertJsonPath('data.grant_type', 'free')
        ->assertJsonPath('data.limits.projects', 10);
})->with(['focus', 'clarity'])->with(['revoked', 'superseded', 'expired', 'future', 'missing expiry', 'free grant']);

it('keeps assigned access when its catalog offer is retired', function (bool $deleted): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'focus')->sole();
    PlanAssignment::factory()->for($user)->for($plan)->create();
    if ($deleted) {
        $plan->deleteOrFail();
    } else {
        $plan->updateOrFail(['is_active' => false]);
    }
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'focus')
        ->assertJsonPath('data.limits.resources', 1000);
    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(2, 'data');
})->with(['inactive' => [false], 'soft deleted' => [true]]);

it('ignores legacy lifetime access while preserving readable history', function (bool $hasExpiry): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'clarity')->sole();
    $assignment = PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => PlanGrantType::LIFETIME,
        'ends_at' => $hasExpiry ? now()->addMonth() : null,
    ]);
    $before = $assignment->fresh()->getRawOriginal();
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'free')
        ->assertJsonPath('data.grant_type', 'free')
        ->assertJsonPath('data.expires_at', null)
        ->assertJsonPath('data.limits', ['projects' => 10, 'areas' => 5, 'resources' => 100]);

    expect($assignment->fresh()->grant_type)->toBe(PlanGrantType::LIFETIME)
        ->and($assignment->fresh()->getRawOriginal())->toBe($before);
})->with(['without expiry' => [false], 'with expiry' => [true]]);

it('returns recurring unlimited Clarity only until its paid period expires', function (): void {
    $this->travelTo('2026-10-11T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $assignment = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create([
        'ends_at' => '2026-11-11T00:00:00Z',
    ]);
    Passport::actingAs($user);
    $this->travelTo('2026-11-10T23:59:59Z');

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'clarity')
        ->assertJsonPath('data.grant_type', 'recurring')
        ->assertJsonPath('data.expires_at', '2026-11-11T00:00:00.000000Z')
        ->assertJsonPath('data.limits', ['projects' => null, 'areas' => null, 'resources' => null]);

    $this->travelTo('2026-11-11T00:00:00Z');
    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'free')
        ->assertJsonPath('data.grant_type', 'free')
        ->assertJsonPath('data.expires_at', null)
        ->assertJsonPath('data.limits', ['projects' => 10, 'areas' => 5, 'resources' => 100]);

    $this->assertDatabaseHas('plan_assignments', ['id' => $assignment->id, 'status' => 'active', 'ends_at' => '2026-11-11 00:00:00']);
});

it('does not grant recurring paid access through the Free catalog plan', function (): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'free')->sole())->create();
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'free')
        ->assertJsonPath('data.grant_type', 'free')
        ->assertJsonPath('data.expires_at', null);
});

it('returns unlimited effective limits with enforcement disabled without rewriting assignments', function (): void {
    config(['plans.enforcement_enabled' => false]);
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $assignment = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'focus')->sole())->create();
    $before = $assignment->fresh()->getRawOriginal();
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertOk()
        ->assertJsonPath('data.plan.slug', 'focus')
        ->assertJsonPath('data.enforcement_enabled', false)
        ->assertJsonPath('data.limits', ['projects' => null, 'areas' => null, 'resources' => null]);

    expect($assignment->fresh()->getRawOriginal())->toBe($before);
});

it('scopes subscription data and usage to the authenticated account', function (): void {
    $this->seed(PlanSeeder::class);
    $owner = User::factory()->create();
    PlanAssignment::factory()->for($owner)->for(Plan::where('slug', 'clarity')->sole())->create();
    $owner->projects()->create(['name' => 'Private project']);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->getJson(route('subscription.show', ['user_uuid' => $owner->uuid, 'plan_slug' => 'clarity']))
        ->assertOk()->assertJsonPath('data.plan.slug', 'free')
        ->assertJsonPath('data.usage.projects', 0);
    $this->postJson(route('subscription.show'), ['plan_slug' => 'clarity'])->assertStatus(405);

    $this->assertDatabaseCount('plan_assignments', 1);
});

it('requires authentication to read a subscription', function (): void {
    $this->getJson(route('subscription.show'))->assertUnauthorized();
});

it('requires email verification even without a JSON accept header', function (): void {
    Passport::actingAs(User::factory()->unverified()->create());

    $this->get(route('subscription.show'))->assertForbidden()->assertJsonMissingPath('code');
});
