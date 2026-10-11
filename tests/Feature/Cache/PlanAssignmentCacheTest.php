<?php

use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Plan\PlanAssignmentService;
use App\Services\Plan\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

pest()->use(DatabaseMigrations::class)->group('pest-features');

function planCacheRequest(User $user): Request
{
    $request = Request::create('http://localhost/cached-plan', 'GET');
    $request->setUserResolver(fn (): User => $user);
    $request->setRouteResolver(fn (): Route => (new Route('GET', 'cached-plan', fn () => null))->name('project.index'));

    return $request;
}

it('refreshes only the affected accounts cached reads on assignment changes', function (): void {
    $this->freezeTime();
    $this->seed(PlanSeeder::class);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $cache = app(ApiReadCacheService::class);
    $calls = [];
    $read = function (User $user) use ($cache, &$calls): array {
        return $cache->response(planCacheRequest($user), function () use ($user, &$calls) {
            $calls[$user->id] = ($calls[$user->id] ?? 0) + 1;

            return response()->json(app(PlanEntitlementService::class)->resolve($user));
        })->getData(true);
    };
    $read($owner);
    $read($other);
    $service = app(PlanAssignmentService::class);

    $service->assign($owner, Plan::where('slug', 'focus')->sole(), PlanGrantType::RECURRING,
        CarbonImmutable::now()->addMonth(), 'upgrade');
    expect($read($owner)['plan']['slug'])->toBe('focus');
    expect($read($other)['plan']['slug'])->toBe('free');
    expect($calls)->toBe([$owner->id => 2, $other->id => 1]);

    $expiresAt = CarbonImmutable::now()->addMonth()->startOfSecond();
    $service->assign($owner, Plan::where('slug', 'clarity')->sole(), PlanGrantType::RECURRING, $expiresAt, 'clarity');
    $clarity = $read($owner);
    expect($clarity['plan']['slug'])->toBe('clarity');
    expect($clarity['grant_type'])->toBe('recurring');
    expect($clarity['expires_at'])->toBe($expiresAt->toISOString());

    $service->revoke($owner);
    expect($read($owner)['plan']['slug'])->toBe('free');
    expect($calls[$owner->id])->toBe(4);
});

it('refreshes only converted accounts cached reads when legacy Clarity access becomes monthly', function (): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    PlanAssignment::factory()->for($owner)->for(Plan::where('slug', 'clarity')->sole())->create([
        'grant_type' => PlanGrantType::LIFETIME,
        'ends_at' => null,
    ]);
    $cache = app(ApiReadCacheService::class);
    $calls = [];
    $read = function (User $user) use ($cache, &$calls): array {
        return $cache->response(planCacheRequest($user), function () use ($user, &$calls) {
            $calls[$user->id] = ($calls[$user->id] ?? 0) + 1;

            return response()->json(app(PlanEntitlementService::class)->resolve($user));
        })->getData(true);
    };
    expect($read($owner)['plan']['slug'])->toBe('free');
    expect($read($other)['plan']['slug'])->toBe('free');

    expect(app(PlanAssignmentService::class)->convertLifetime())->toBe(1);

    $clarity = $read($owner);
    expect($clarity['plan']['slug'])->toBe('clarity');
    expect($clarity['grant_type'])->toBe('recurring');
    expect($clarity['expires_at'])->toBe('2026-11-10T00:00:00.000000Z');
    expect($read($other)['plan']['slug'])->toBe('free');
    expect($calls)->toBe([$owner->id => 2, $other->id => 1]);
});

it('invalidates cached reads when an assignment is corrected directly', function (): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $assignment = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'focus')->sole())->create();
    $cache = app(ApiReadCacheService::class);
    $request = planCacheRequest($user);
    $resolve = fn () => response()->json(app(PlanEntitlementService::class)->resolve($user));
    expect($cache->response($request, $resolve)->getData(true)['plan']['slug'])->toBe('focus');

    $assignment->updateOrFail(['ends_at' => now()->subSecond()]);

    expect($cache->response($request, $resolve)->getData(true)['plan']['slug'])->toBe('free');
});

it('rolls back assignment history and its cache revision together', function (): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $cache = app(ApiReadCacheService::class);
    $request = planCacheRequest($user);
    $calls = 0;
    $resolve = function () use ($user, &$calls) {
        $calls++;

        return response()->json(app(PlanEntitlementService::class)->resolve($user));
    };
    $cache->response($request, $resolve);

    expect(fn () => DB::transaction(function () use ($user): void {
        app(PlanAssignmentService::class)->assign(
            $user, Plan::where('slug', 'focus')->sole(), PlanGrantType::RECURRING,
            CarbonImmutable::now()->addMonth(), 'rolled-back',
        );
        throw new RuntimeException('Abort assignment.');
    }))->toThrow(RuntimeException::class, 'Abort assignment.');

    expect($cache->response($request, $resolve)->getData(true)['plan']['slug'])->toBe('free');
    expect($calls)->toBe(1);
    $this->assertDatabaseEmpty('plan_assignments');
});

it('rolls back lifetime conversion history and its cache revision together', function (): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    $legacy = PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create([
        'grant_type' => PlanGrantType::LIFETIME,
        'ends_at' => null,
    ]);
    $cache = app(ApiReadCacheService::class);
    $request = planCacheRequest($user);
    $calls = 0;
    $resolve = function () use ($user, &$calls) {
        $calls++;

        return response()->json(app(PlanEntitlementService::class)->resolve($user));
    };
    expect($cache->response($request, $resolve)->getData(true)['plan']['slug'])->toBe('free');
    $revisionKey = 'api-read:revision:user:'.$user->uuid;
    $revision = Cache::store('api_read_revisions')->get($revisionKey);

    expect(fn () => DB::transaction(function (): void {
        expect(app(PlanAssignmentService::class)->convertLifetime())->toBe(1);

        throw new RuntimeException('Abort lifetime conversion.');
    }))->toThrow(RuntimeException::class, 'Abort lifetime conversion.');

    $this->assertDatabaseCount('plan_assignments', 1);
    $this->assertDatabaseHas('plan_assignments', [
        'id' => $legacy->id,
        'status' => 'active',
        'grant_type' => 'lifetime',
        'ends_at' => null,
    ]);
    expect(Cache::store('api_read_revisions')->get($revisionKey))->toBe($revision);
    expect($cache->response($request, $resolve)->getData(true)['plan']['slug'])->toBe('free');
    expect($calls)->toBe(1);
});

it('resolves start and expiry boundaries live despite long read cache lifetimes', function (string $planSlug): void {
    $this->travelTo('2026-10-10T00:00:00Z');
    config(['cache.api_reads.ttl' => 3600]);
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', $planSlug)->sole())->create([
        'starts_at' => now()->addSecond(), 'ends_at' => now()->addSeconds(2),
    ]);
    Passport::actingAs($user);

    $this->getJson(route('subscription.show'))->assertJsonPath('data.plan.slug', 'free');
    $this->travel(1)->seconds();
    $this->getJson(route('subscription.show'))->assertJsonPath('data.plan.slug', $planSlug)
        ->assertJsonPath('data.grant_type', 'recurring')
        ->assertJsonPath('data.expires_at', '2026-10-10T00:00:02.000000Z');
    $this->travel(1)->seconds();
    $this->getJson(route('subscription.show'))->assertJsonPath('data.plan.slug', 'free');
    config(['plans.enforcement_enabled' => false]);
    $this->getJson(route('subscription.show'))->assertJsonPath('data.limits.projects', null);
})->with(['focus', 'clarity']);

it('refreshes the public catalog when required definitions are reseeded', function (): void {
    $this->seed(PlanSeeder::class);
    $this->getJson(route('plan.index'))->assertJsonPath('data.0.limits.projects', 10);
    Plan::where('slug', 'free')->sole()->updateOrFail([
        'limits' => ['projects' => 99, 'areas' => 5, 'resources' => 100],
    ]);
    $this->getJson(route('plan.index'))->assertJsonPath('data.0.limits.projects', 99);

    $this->seed(PlanSeeder::class);

    $this->getJson(route('plan.index'))->assertJsonPath('data.0.limits.projects', 10);
});
