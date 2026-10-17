<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('publishes seeded plan prices, billing intervals, limits, and features to guests', function (): void {
    $this->seed(PlanSeeder::class);

    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.slug', 'free')->assertJsonPath('data.0.price_minor', 0)
        ->assertJsonPath('data.0.currency', 'PHP')->assertJsonPath('data.0.billing_interval', null)
        ->assertJsonPath('data.0.limits', ['projects' => 10, 'areas' => 5, 'resources' => 100, 'storage_mb' => 1024])
        ->assertJsonPath('data.0.features', [
            'Up to 10 projects', 'Up to 5 areas', 'Up to 100 resources', '1 GB storage',
            'Every tool: Boards, Focus, Habits, Notes, Journal, Letters, and Calendar',
        ])
        ->assertJsonPath('data.1.slug', 'focus')->assertJsonPath('data.1.price_minor', 9900)
        ->assertJsonPath('data.1.billing_interval', 'month')
        ->assertJsonPath('data.1.limits', ['projects' => 50, 'areas' => 20, 'resources' => 1000, 'storage_mb' => 10240])
        ->assertJsonPath('data.1.features.0', 'Up to 50 projects')
        ->assertJsonPath('data.1.features.2', 'Up to 1,000 resources')
        ->assertJsonPath('data.1.features.3', '10 GB storage')
        ->assertJsonPath('data.2.slug', 'clarity')->assertJsonPath('data.2.price_minor', 14900)
        ->assertJsonPath('data.2.billing_interval', 'month')
        ->assertJsonPath('data.2.limits', ['projects' => null, 'areas' => null, 'resources' => null, 'storage_mb' => 102400])
        ->assertJsonPath('data.2.features.0', 'Unlimited projects')
        ->assertJsonPath('data.2.features.3', '100 GB storage');

    $this->assertDatabaseCount('plans', 3);
});

it('exposes only public catalog fields', function (): void {
    $this->seed(PlanSeeder::class);

    $plan = $this->getJson(route('plan.index'))->assertOk()->json('data.0');

    expect(array_keys($plan))->toBe([
        'uuid', 'slug', 'name', 'description', 'price_minor', 'currency', 'billing_interval', 'limits', 'features',
    ]);
});

it('orders the catalog by price', function (): void {
    Plan::factory()->create(['slug' => 'premium', 'price_minor' => 50000]);
    Plan::factory()->create(['slug' => 'basic', 'price_minor' => 1000]);

    $this->getJson(route('plan.index'))->assertOk()
        ->assertJsonPath('data.0.slug', 'basic')->assertJsonPath('data.1.slug', 'premium');
});

it('syncs the seeded catalog with the tier definitions in config', function (): void {
    $this->seed(PlanSeeder::class);

    foreach (config('plans.tiers') as $slug => $tier) {
        $plan = Plan::where('slug', $slug)->sole();
        expect($plan->name)->toBe($tier['name'])
            ->and($plan->description)->toBe($tier['description'])
            ->and($plan->price_minor)->toBe($tier['price_minor'])
            ->and($plan->currency->value)->toBe($tier['currency'])
            ->and($plan->billing_interval?->value)->toBe($tier['billing_interval'])
            ->and(array_intersect_key($plan->limits, $tier['limits']))->toEqual($tier['limits']);
    }
});

it('omits a deleted plan from the public catalog', function (): void {
    $active = Plan::factory()->create(['name' => 'Available']);
    $deleted = Plan::factory()->create(['name' => 'Retired']);
    $deleted->deleteOrFail();

    $this->getJson(route('plan.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $active->uuid)->assertJsonMissing(['name' => 'Retired']);

    $this->assertSoftDeleted($deleted);
});
