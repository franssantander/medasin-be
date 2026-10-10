<?php

namespace Tests\Feature\Database;

use App\Enum\PlanGrantType;
use App\Models\Board;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Services\Board\BoardService;
use App\Services\Plan\PlanAssignmentService;
use App\Services\Trash\TrashService;
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
        $this->travelTo('2026-10-10 08:00:00');
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->where('email', 'focus@example.com')->firstOrFail();
        $user->update(['first_name' => 'Edited', 'password' => 'my-edited-password']);
        $password = $user->getRawOriginal('password');
        $area = $user->areas()->where('slug', 'health')->firstOrFail();
        $area->update(['name' => 'My health', 'archived_at' => now()]);
        $project = $user->projects()->where('slug', '10k-training-plan')->firstOrFail();
        $project->update(['name' => 'My training plan']);
        $dueDate = $project->due_date->toDateString();
        $note = $area->notes()->firstOrFail();
        $entry = app(TrashService::class)->delete($user, $note, 'note', $note->title, $area->name);
        $entryAttributes = $entry->fresh()->getRawOriginal();
        $assignment = app(PlanAssignmentService::class)->assign(
            $user,
            Plan::query()->where('slug', 'free')->sole(),
            PlanGrantType::FREE,
            null,
            'edited-demo-plan',
        );
        $assignmentAttributes = $assignment->fresh()->getRawOriginal();
        $clarityAssignment = User::query()->where('email', 'clarity@example.com')->sole()->planAssignments()->sole();
        $clarityAssignmentAttributes = $clarityAssignment->getRawOriginal();
        $otherUser = User::factory()->create();
        $otherUserAttributes = $otherUser->fresh()->getRawOriginal();
        $legacy = User::factory()->create(['email' => 'test@example.com']);
        $legacyAttributes = $legacy->fresh()->getRawOriginal();
        $this->travel(7)->days();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('oauth_clients', 1);
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('areas', 18);
        $this->assertDatabaseCount('projects', 20);
        $this->assertDatabaseCount('resources', 10);
        $this->assertSame($password, $user->fresh()->getRawOriginal('password'));
        $this->assertSame('Edited', $user->fresh()->first_name);
        $this->assertSame('My health', $area->fresh()->name);
        $this->assertNotNull($area->fresh()->archived_at);
        $this->assertSame('My training plan', $project->fresh()->name);
        $this->assertSame($dueDate, $project->fresh()->due_date->toDateString());
        $this->assertSoftDeleted($note);
        $this->assertSame($entryAttributes, $entry->fresh()->getRawOriginal());
        $this->assertSame($assignmentAttributes, $assignment->fresh()->getRawOriginal());
        $this->assertSame($clarityAssignmentAttributes, $clarityAssignment->fresh()->getRawOriginal());
        $this->assertSame($otherUserAttributes, $otherUser->fresh()->getRawOriginal());
        $this->assertSame($legacyAttributes, $legacy->fresh()->getRawOriginal());
    }

    public function test_existing_legacy_account_does_not_prevent_creating_missing_demo_profiles(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'test@example.com']);
        $attributes = $user->fresh()->getRawOriginal();

        $this->seed(DemoUserSeeder::class);

        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('areas', 18);
        $this->assertDatabaseCount('projects', 20);
        $this->assertDatabaseCount('resources', 10);
        $this->assertSame($attributes, $user->fresh()->getRawOriginal());
        $this->assertCount(0, $user->areas);
        $this->assertCount(0, $user->projects);
        $this->assertCount(0, $user->resources);
        $this->assertCount(0, $user->planAssignments);
    }

    public function test_failed_demo_initialization_rolls_back_and_can_be_retried(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $boards = $this->app->make(BoardService::class);
        $projectBoardCalls = 0;
        $this->partialMock(BoardService::class)
            ->shouldReceive('createForProject')
            ->times(11)
            ->andReturnUsing(function (User $user, Project $project, ?string $name = null) use ($boards, &$projectBoardCalls): Board {
                $projectBoardCalls++;
                if ($projectBoardCalls === 11) {
                    $this->assertSame('clarity@example.com', $user->email);
                    $focus = User::query()->where('email', 'focus@example.com')->sole();
                    $this->assertSame(10, $focus->projects()->withTrashed()->count());
                    $this->assertSame(5, $focus->resources()->withTrashed()->count());
                    $this->assertNotEmpty(Storage::disk('public')->allFiles());
                    $this->assertNotEmpty(Storage::disk('local')->allFiles());

                    throw new RuntimeException('Demo board initialization failed.');
                }

                return $boards->createForProject($user, $project, $name);
            });

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
        $this->assertDatabaseEmpty('resources');
        $this->assertDatabaseEmpty('boards');
        $this->assertDatabaseEmpty('board_stages');
        $this->assertDatabaseEmpty('board_tasks');
        $this->assertDatabaseEmpty('plan_assignments');
        $this->assertDatabaseEmpty('trash_entries');
        $this->assertDatabaseEmpty('resource_tags');
        $this->assertDatabaseEmpty('resource_attachments');
        $this->assertDatabaseEmpty('board_labels');
        $this->assertDatabaseEmpty('focus_settings');
        $this->assertDatabaseEmpty('focus_tasks');
        $this->assertDatabaseEmpty('focus_sessions');
        $this->assertDatabaseEmpty('journal_entries');
        $this->assertDatabaseEmpty('letters');
        $this->assertDatabaseEmpty('calendar_plans');
        Storage::disk('public')->assertEmpty();
        Storage::disk('local')->assertEmpty();

        $this->app->forgetInstance(BoardService::class);
        $this->seed(DemoUserSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('areas', 18);
        $this->assertDatabaseCount('projects', 20);
        $this->assertDatabaseCount('resources', 10);
    }

    public function test_repeated_seeding_updates_required_plan_definitions_without_duplicates(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        User::factory()->create(['email' => 'test@example.com']);
        $this->seed(DatabaseSeeder::class);
        Plan::query()->where('slug', 'free')->update(['price' => 50]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseHas('plans', ['slug' => 'free', 'price' => 0]);
    }
}
