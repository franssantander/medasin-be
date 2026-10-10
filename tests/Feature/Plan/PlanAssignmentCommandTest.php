<?php

use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use App\Services\Plan\PlanEntitlementService;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('assigns dated paid access and preserves the superseded grant', function (): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $old = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'free')->sole())->create([
        'grant_type' => PlanGrantType::FREE, 'ends_at' => null,
    ]);

    $this->artisan('plans:assign', [
        'user_uuid' => $user->uuid, 'plan_slug' => 'focus',
        '--reference' => 'manual-upgrade', '--expires-at' => '2026-11-10T08:00:00+08:00',
    ])->assertSuccessful();

    $this->assertDatabaseHas('plan_assignments', ['id' => $old->id, 'status' => 'superseded', 'ends_at' => null]);
    $this->assertDatabaseHas('plan_assignments', [
        'user_id' => $user->id, 'plan_id' => Plan::where('slug', 'focus')->value('id'),
        'status' => 'active', 'grant_type' => 'recurring', 'source' => 'admin',
        'source_reference' => 'manual-upgrade', 'ends_at' => '2026-11-10 00:00:00',
    ]);
    $this->assertDatabaseCount('plan_assignments', 2);
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('focus');
});

it('replays an expired reference without renewing or duplicating its grant', function (): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $arguments = [
        'user_uuid' => $user->uuid, 'plan_slug' => 'focus',
        '--reference' => 'dated-access', '--expires-at' => '2026-10-10T00:01:00Z',
    ];
    $this->artisan('plans:assign', $arguments)->assertSuccessful();
    $before = $user->planAssignments()->sole()->getRawOriginal();
    $this->travel(2)->minutes();

    $this->artisan('plans:assign', $arguments)->assertSuccessful();

    expect($user->planAssignments()->sole()->getRawOriginal())->toBe($before);
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('free');
    $this->assertDatabaseCount('plan_assignments', 1);
});

it('rejects conflicting reuse of a grant reference without superseding current access', function (string $conflict): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $arguments = [
        'user_uuid' => $user->uuid, 'plan_slug' => 'focus',
        '--reference' => 'unique-grant', '--expires-at' => '2026-11-10T00:00:00Z',
    ];
    $this->artisan('plans:assign', $arguments)->assertSuccessful();
    $replacement = match ($conflict) {
        'account' => ['user_uuid' => User::factory()->create()->uuid],
        'plan' => ['plan_slug' => 'clarity'],
        'period' => ['--expires-at' => '2026-12-10T00:00:00Z'],
    };

    $this->artisan('plans:assign', array_replace($arguments, $replacement))->assertFailed();

    $this->assertDatabaseCount('plan_assignments', 1);
    $this->assertDatabaseHas('plan_assignments', ['user_id' => $user->id, 'status' => 'active']);
})->with(['account', 'plan', 'period']);

it('rejects invalid operator grants without writing assignment history', function (string $slug, array $options): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();

    $this->artisan('plans:assign', [
        'user_uuid' => $user->uuid, 'plan_slug' => $slug, '--reference' => 'invalid-grant', ...$options,
    ])->assertFailed();

    $this->assertDatabaseEmpty('plan_assignments');
})->with([
    'missing reference' => ['focus', ['--reference' => null, '--expires-at' => '2026-11-10T00:00:00Z']],
    'missing paid expiry' => ['focus', []],
    'expired paid access' => ['focus', ['--expires-at' => '2026-10-10T00:00:00Z']],
    'ambiguous timezone' => ['focus', ['--expires-at' => '2026-11-10 00:00:00']],
    'invalid calendar date' => ['focus', ['--expires-at' => '2026-02-30T00:00:00Z']],
    'free expiry' => ['free', ['--expires-at' => '2026-11-10T00:00:00Z']],
    'focus lifetime' => ['focus', ['--lifetime' => true]],
    'lifetime expiry' => ['clarity', ['--lifetime' => true, '--expires-at' => '2026-11-10T00:00:00Z']],
    'unknown plan' => ['unknown', ['--expires-at' => '2026-11-10T00:00:00Z']],
]);

it('rejects new grants against a retired offer', function (bool $deleted): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $plan = Plan::where('slug', 'focus')->sole();
    if ($deleted) {
        $plan->deleteOrFail();
    } else {
        $plan->updateOrFail(['is_active' => false]);
    }

    $this->artisan('plans:assign', [
        'user_uuid' => $user->uuid, 'plan_slug' => 'focus',
        '--reference' => 'retired-offer', '--expires-at' => now()->addMonth()->toIso8601String(),
    ])->assertFailed();

    $this->assertDatabaseEmpty('plan_assignments');
})->with(['inactive' => [false], 'deleted' => [true]]);

it('grants permanent Clarity access through an explicit lifetime operation', function (): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();

    $this->artisan('plans:assign', [
        'user_uuid' => $user->uuid, 'plan_slug' => 'clarity',
        '--reference' => 'permanent-grant', '--lifetime' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('plan_assignments', [
        'user_id' => $user->id, 'grant_type' => 'lifetime', 'status' => 'active', 'ends_at' => null,
    ]);
});

it('revokes access idempotently without deleting its history', function (): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $assignment = PlanAssignment::factory()->lifetime()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create();

    $this->artisan('plans:revoke', ['user_uuid' => $user->uuid])->assertSuccessful();
    $this->artisan('plans:revoke', ['user_uuid' => $user->uuid])->assertSuccessful();

    $this->assertDatabaseCount('plan_assignments', 1);
    $this->assertDatabaseHas('plan_assignments', ['id' => $assignment->id, 'status' => 'revoked']);
    expect(app(PlanEntitlementService::class)->resolve($user)['plan']['slug'])->toBe('free');
});

it('backfills only accounts without any assignment history and can be repeated', function (): void {
    $this->seed(PlanSeeder::class);
    $unassigned = User::factory()->create();
    $paid = User::factory()->create();
    $expired = User::factory()->create();
    $focus = Plan::where('slug', 'focus')->sole();
    $paidAssignment = PlanAssignment::factory()->for($paid)->for($focus)->create();
    $expiredAssignment = PlanAssignment::factory()->for($expired)->for($focus)->create(['ends_at' => now()->subDay()]);

    $this->artisan('plans:backfill-free')->expectsOutput('Created 1 Free plan assignment(s).')->assertSuccessful();
    $this->artisan('plans:backfill-free')->expectsOutput('Created 0 Free plan assignment(s).')->assertSuccessful();

    $this->assertDatabaseCount('plan_assignments', 3);
    $this->assertDatabaseHas('plan_assignments', ['user_id' => $unassigned->id, 'grant_type' => 'free', 'source' => 'backfill']);
    $this->assertModelExists($paidAssignment);
    $this->assertModelExists($expiredAssignment);
});

it('reports missing catalog setup before backfilling accounts', function (): void {
    User::factory()->create();

    $this->artisan('plans:backfill-free')->assertFailed();

    $this->assertDatabaseEmpty('plan_assignments');
});

it('reruns catalog seeding without reviving offers or changing assignment history', function (): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $focus = Plan::where('slug', 'focus')->sole();
    $assignment = PlanAssignment::factory()->for($user)->for($focus)->create();
    $before = $assignment->fresh()->getRawOriginal();
    $focus->updateOrFail(['is_active' => false]);
    Plan::where('slug', 'clarity')->sole()->deleteOrFail();

    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->assertDatabaseCount('plans', 3);
    expect($focus->fresh()->is_active)->toBeFalse();
    $this->assertSoftDeleted('plans', ['slug' => 'clarity']);
    expect($assignment->fresh()->getRawOriginal())->toBe($before);
});

it('prevents permanent catalog deletion while assignments reference it', function (): void {
    $plan = Plan::factory()->create();
    PlanAssignment::factory()->for($plan)->create();

    expect(fn () => $plan->forceDelete())->toThrow(QueryException::class);
});

it('removes private assignment records when their account is deleted', function (): void {
    $assignment = PlanAssignment::factory()->create();

    $assignment->user->deleteOrFail();

    $this->assertModelMissing($assignment);
});
