<?php

namespace Tests\Feature\Database;

use App\Models\Plan;
use App\Models\User;
use App\Services\Board\BoardService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_seeding_preserves_existing_users_and_demo_changes(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'test@example.com')->firstOrFail();
        $password = $user->getRawOriginal('password');
        $user->update(['first_name' => 'Edited']);
        $area = $user->areas()->where('slug', 'health')->firstOrFail();
        $area->update(['name' => 'My health', 'archived_at' => now()]);
        $project = $user->projects()->where('slug', '10k-training-plan')->firstOrFail();
        $project->update(['name' => 'My training plan']);
        $note = $area->notes()->firstOrFail();
        $note->delete();
        $otherUser = User::factory()->create();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 12);
        $this->assertDatabaseCount('oauth_clients', 1);
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('areas', 7);
        $this->assertDatabaseCount('projects', 8);
        $this->assertSame($password, $user->fresh()->getRawOriginal('password'));
        $this->assertSame('Edited', $user->fresh()->first_name);
        $this->assertSame('My health', $area->fresh()->name);
        $this->assertNotNull($area->fresh()->archived_at);
        $this->assertSame('My training plan', $project->fresh()->name);
        $this->assertSoftDeleted($note);
        $this->assertModelExists($otherUser);
    }

    public function test_existing_demo_account_prevents_creating_demo_content(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->seed(DemoUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('areas');
        $this->assertDatabaseEmpty('projects');
        $this->assertModelExists($user);
    }

    public function test_failed_demo_initialization_rolls_back_and_can_be_retried(): void
    {
        Storage::fake('public');
        $this->mock(BoardService::class)
            ->shouldReceive('createForProject')
            ->once()
            ->andThrow(new RuntimeException('Demo board initialization failed.'));

        try {
            $this->seed(DemoUserSeeder::class);
            $this->fail('Demo initialization should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Demo board initialization failed.', $exception->getMessage());
        }

        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('areas');
        $this->assertDatabaseEmpty('goals');
        $this->assertDatabaseEmpty('habits');
        $this->assertDatabaseEmpty('notes');
        $this->assertDatabaseEmpty('projects');

        $this->app->forgetInstance(BoardService::class);
        $this->seed(DemoUserSeeder::class);

        $this->assertDatabaseCount('users', 11);
        $this->assertDatabaseCount('areas', 7);
        $this->assertDatabaseCount('projects', 8);
    }

    public function test_repeated_seeding_updates_required_plan_definitions_without_duplicates(): void
    {
        User::factory()->create(['email' => 'test@example.com']);
        $this->seed(DatabaseSeeder::class);
        Plan::query()->where('slug', 'free')->update(['price' => 50]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseHas('plans', ['slug' => 'free', 'price' => 0]);
    }
}
