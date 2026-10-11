<?php

use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use App\Services\Plan\PlanAssignmentService;
use App\Services\Plan\PlanEntitlementService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('converts current lifetime Clarity to one calendar month in UTC without overflowing month end', function (): void {
    $this->travelTo('2026-01-31T20:15:30.456789+08:00');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'clarity')->sole();
    $legacy = PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null,
        'starts_at' => '2025-12-01T00:00:00Z', 'source_reference' => 'original-grant',
    ]);
    $history = Arr::except($legacy->fresh()->getRawOriginal(), ['status', 'updated_at']);
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    $this->artisan('plans:convert-lifetime')
        ->expectsOutput('Converted 1 lifetime plan assignment(s).')->assertSuccessful();

    $this->assertDatabaseCount('plan_assignments', 2);
    $this->assertDatabaseHas('plan_assignments', ['id' => $legacy->id, 'status' => 'superseded']);
    expect(Arr::except($legacy->fresh()->getRawOriginal(), ['status', 'updated_at']))->toBe($history);
    $converted = $user->planAssignments()->where('source', 'lifetime-conversion')->sole();
    $this->assertDatabaseHas('plan_assignments', [
        'id' => $converted->id, 'plan_id' => $plan->id, 'status' => 'active',
        'grant_type' => 'recurring', 'starts_at' => '2026-01-31 12:15:30',
        'ends_at' => '2026-02-28 12:15:30', 'source_reference' => $user->uuid,
    ]);
    expect(Cache::store('api_read_revisions')->get($revisionKey))->not->toBe($revision);
    expect(app(PlanEntitlementService::class)->resolve($user))->toMatchArray([
        'plan' => ['slug' => 'clarity', 'name' => 'Clarity'], 'grant_type' => 'recurring',
        'expires_at' => '2026-02-28T12:15:30.000000Z',
        'limits' => ['projects' => null, 'areas' => null, 'resources' => null],
    ]);
});

it('never converts an account twice even when older active lifetime rows remain', function (
    PlanAssignmentStatus $status, string $rerunAt,
): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'clarity')->sole();
    $older = PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null, 'starts_at' => now()->subMonths(2),
    ]);
    PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null, 'starts_at' => now()->subMonth(),
    ]);
    $service = app(PlanAssignmentService::class);
    expect($service->convertLifetime())->toBe(1);
    $converted = $user->planAssignments()->where('source', 'lifetime-conversion')->sole();
    $converted->updateOrFail(['status' => $status]);
    $before = $user->planAssignments()->orderBy('id')->get()->map->getRawOriginal()->all();
    $this->travelTo($rerunAt);

    $this->artisan('plans:convert-lifetime')
        ->expectsOutput('Converted 0 lifetime plan assignment(s).')->assertSuccessful();

    expect($user->planAssignments()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
    $this->assertDatabaseHas('plan_assignments', ['id' => $older->id, 'status' => 'active']);
})->with([
    'active conversion' => [PlanAssignmentStatus::ACTIVE, '2026-10-11T00:00:00Z'],
    'expired conversion' => [PlanAssignmentStatus::ACTIVE, '2026-11-10T00:00:00Z'],
    'revoked conversion' => [PlanAssignmentStatus::REVOKED, '2026-10-11T00:00:00Z'],
]);

it('skips lifetime rows that are not current eligible Clarity assignments', function (
    string $slug, array $attributes,
): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $assignment = PlanAssignment::factory()->for($user)->for(Plan::where('slug', $slug)->sole())->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null,
        'starts_at' => '2026-10-09T00:00:00Z', ...$attributes,
    ]);
    $before = $assignment->fresh()->getRawOriginal();

    $this->artisan('plans:convert-lifetime')
        ->expectsOutput('Converted 0 lifetime plan assignment(s).')->assertSuccessful();

    $this->assertDatabaseCount('plan_assignments', 1);
    expect($assignment->fresh()->getRawOriginal())->toBe($before);
})->with([
    'revoked' => ['clarity', ['status' => PlanAssignmentStatus::REVOKED]],
    'superseded' => ['clarity', ['status' => PlanAssignmentStatus::SUPERSEDED]],
    'future' => ['clarity', ['starts_at' => '2026-10-11T00:00:00Z']],
    'expired' => ['clarity', ['ends_at' => '2026-10-10T00:00:00Z']],
    'different plan' => ['focus', []],
]);

it('preserves a newer current recurring assignment rather than converting an older lifetime row', function (
    string $recurringStartsAt,
): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null, 'starts_at' => '2026-10-08T00:00:00Z',
    ]);
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'focus')->sole())->create([
        'starts_at' => $recurringStartsAt,
    ]);
    $before = $user->planAssignments()->orderBy('id')->get()->map->getRawOriginal()->all();

    expect(app(PlanAssignmentService::class)->convertLifetime())->toBe(0);

    expect($user->planAssignments()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('focus');
})->with([
    'later start' => ['2026-10-09T00:00:00Z'],
    'same start and later id' => ['2026-10-08T00:00:00Z'],
]);

it('selects current access by start before id and preserves future recurring access', function (): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null, 'starts_at' => '2026-10-09T00:00:00Z',
    ]);
    $older = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'focus')->sole())->create([
        'starts_at' => '2026-10-08T00:00:00Z',
    ]);
    $future = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'focus')->sole())->create([
        'starts_at' => '2026-10-11T00:00:00Z',
    ]);
    $olderBefore = $older->fresh()->getRawOriginal();
    $futureBefore = $future->fresh()->getRawOriginal();

    expect(app(PlanAssignmentService::class)->convertLifetime())->toBe(1);

    expect($older->fresh()->getRawOriginal())->toBe($olderBefore);
    expect($future->fresh()->getRawOriginal())->toBe($futureBefore);
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('clarity');
    $this->travelTo('2026-10-11T00:00:00Z');
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('focus');
});

it('converts existing access to retired Clarity without reviving its catalog offer', function (bool $deleted): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'clarity')->sole();
    PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null,
    ]);
    if ($deleted) {
        $plan->deleteOrFail();
    } else {
        $plan->updateOrFail(['is_active' => false]);
    }
    $before = Plan::withTrashed()->findOrFail($plan->id)->getRawOriginal();

    expect(app(PlanAssignmentService::class)->convertLifetime())->toBe(1);

    expect(Plan::withTrashed()->findOrFail($plan->id)->getRawOriginal())->toBe($before);
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('clarity');
})->with(['inactive' => [false], 'soft deleted' => [true]]);

it('rejects an invalid historical Clarity definition without changing access and can retry', function (): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'clarity')->sole();
    $legacy = PlanAssignment::factory()->for($user)->for($plan)->create([
        'grant_type' => PlanGrantType::LIFETIME, 'ends_at' => null,
    ]);
    $plan->updateOrFail(['limits' => ['projects' => null, 'areas' => null]]);
    $before = $legacy->fresh()->getRawOriginal();
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    expect(fn (): int => app(PlanAssignmentService::class)->convertLifetime())
        ->toThrow(RuntimeException::class, 'A required Core plan limit is missing.');

    $this->assertDatabaseCount('plan_assignments', 1);
    expect($legacy->fresh()->getRawOriginal())->toBe($before);
    expect(Cache::store('api_read_revisions')->get($revisionKey))->toBe($revision);
    $plan->updateOrFail(['limits' => ['projects' => null, 'areas' => null, 'resources' => null]]);
    expect(app(PlanAssignmentService::class)->convertLifetime())->toBe(1);
    $this->assertDatabaseCount('plan_assignments', 2);
});
