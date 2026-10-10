<?php

use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\TrashEntry;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

function createQuotaItems(User $user, string $feature, int $count, array $attributes = []): void
{
    for ($index = 0; $index < $count; $index++) {
        $user->{$feature}()->create([
            $feature === 'resources' ? 'title' : 'name' => "Existing {$feature} {$index}",
            ...$attributes,
        ]);
    }
}

it('allows Clarity usage beyond the Focus maximum', function (
    string $feature, string $routeName, string $field, int $existing,
): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create();
    createQuotaItems($user, $feature, $existing);
    Passport::actingAs($user);

    $this->postJson(route($routeName), [$field => 'Unlimited Clarity item'])->assertCreated();

    $this->assertDatabaseCount($feature, $existing + 1);
})->with([
    'projects' => ['projects', 'project.store', 'name', 51],
    'areas' => ['areas', 'area.store', 'name', 21],
    'resources' => ['resources', 'resource.store', 'title', 1001],
]);

it('allows the final available item and returns 403 for the next item', function (
    string $slug, string $feature, string $routeName, string $field, int $limit,
): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    if ($slug !== 'free') {
        PlanAssignment::factory()->for($user)->for(Plan::where('slug', $slug)->sole())->create();
    }
    createQuotaItems($user, $feature, $limit - 1);
    Passport::actingAs($user);

    $this->postJson(route($routeName), [$field => 'Final available item'])->assertCreated();
    $this->postJson(route($routeName), [$field => 'Over the limit'])->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('data', null)
        ->assertJsonPath('status', 403)
        ->assertJsonPath('meta', ['feature' => $feature, 'usage' => $limit, 'limit' => $limit])
        ->assertJsonMissingPath('errors');

    $this->assertDatabaseCount($feature, $limit);
})->with([
    'Free projects' => ['free', 'projects', 'project.store', 'name', 10],
    'Free areas' => ['free', 'areas', 'area.store', 'name', 5],
    'Free resources' => ['free', 'resources', 'resource.store', 'title', 100],
    'Focus projects' => ['focus', 'projects', 'project.store', 'name', 50],
    'Focus areas' => ['focus', 'areas', 'area.store', 'name', 20],
    'Focus resources' => ['focus', 'resources', 'resource.store', 'title', 1000],
]);

it('does not persist a nested area project or board when either allowance is exhausted', function (string $feature): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail([
        'limits' => ['projects' => $feature === 'projects' ? 0 : 10, 'areas' => $feature === 'areas' ? 0 : 5, 'resources' => 100],
    ]);
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('project.store'), ['name' => 'New project', 'area_name' => 'New area'])
        ->assertForbidden()->assertJsonPath('meta.feature', $feature);

    $this->assertDatabaseEmpty('projects');
    $this->assertDatabaseEmpty('areas');
    $this->assertDatabaseEmpty('boards');
})->with(['projects', 'areas']);

it('checks the area allowance when project reassignment creates an area', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 100]]);
    $user = User::factory()->create();
    $project = $user->projects()->create(['name' => 'Existing project']);
    Passport::actingAs($user);

    $this->patchJson(route('project.area.update', $project), ['area_name' => 'Unavailable slot'])
        ->assertForbidden()->assertJsonPath('meta.feature', 'areas');

    $this->assertDatabaseEmpty('areas');
    $this->assertDatabaseHas('projects', ['id' => $project->id, 'area_id' => null]);
});

it('allows existing area associations when the account is over quota', function (string $input): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 100]]);
    $user = User::factory()->create();
    $project = $user->projects()->create(['name' => 'Existing project']);
    $area = $user->areas()->create(['name' => 'Existing area']);
    Passport::actingAs($user);

    $payload = $input === 'uuid' ? ['area_uuid' => $area->uuid] : ['area_name' => $area->name];
    $this->patchJson(route('project.area.update', $project), $payload)->assertOk();

    $this->assertDatabaseCount('areas', 1);
    $this->assertDatabaseHas('projects', ['id' => $project->id, 'area_id' => $area->id]);
})->with(['uuid', 'name']);

it('reuses an existing area when creating a project at the area limit', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 10, 'areas' => 1, 'resources' => 100]]);
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Existing area']);
    Passport::actingAs($user);

    $this->postJson(route('project.store'), ['name' => 'New project', 'area_name' => $area->name])->assertCreated();

    $this->assertDatabaseCount('areas', 1);
    $this->assertDatabaseHas('projects', ['user_id' => $user->id, 'area_id' => $area->id]);
});

it('counts archived items toward the corresponding cap', function (
    string $feature, string $archiveRoute, string $storeRoute, string $field,
): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 1, 'areas' => 1, 'resources' => 1]]);
    $user = User::factory()->create();
    createQuotaItems($user, $feature, 1);
    $item = $user->{$feature}()->sole();
    Passport::actingAs($user);

    $this->postJson(route($archiveRoute, $item))->assertOk();
    $this->postJson(route($storeRoute), [$field => 'Another item'])->assertForbidden()
        ->assertJsonPath('meta.usage', 1);

    $this->assertDatabaseCount($feature, 1);
    expect($item->fresh()->archived_at)->not->toBeNull();
})->with([
    'projects' => ['projects', 'project.archive', 'project.store', 'name'],
    'areas' => ['areas', 'area.archive', 'area.store', 'name'],
    'resources' => ['resources', 'resource.archive', 'resource.store', 'title'],
]);

it('releases trashed usage and allows recovery beyond the cap', function (string $feature, string $prefix, string $field): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 1, 'areas' => 1, 'resources' => 1]]);
    $user = User::factory()->create();
    createQuotaItems($user, $feature, 1);
    $item = $user->{$feature}()->sole();
    Passport::actingAs($user);

    $this->deleteJson(route($prefix.'.destroy', $item))->assertOk();
    $this->postJson(route($prefix.'.store'), [$field => 'Replacement item'])->assertCreated();
    $entry = TrashEntry::where('subject_uuid', $item->uuid)->sole();
    $this->postJson(route('trash.restore', $entry))->assertOk();
    $this->postJson(route($prefix.'.store'), [$field => 'Another item'])->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('meta', ['feature' => $feature, 'usage' => 2, 'limit' => 1]);

    expect($user->{$feature}()->count())->toBe(2);
    $this->assertDatabaseCount($feature, 2);
    $this->assertModelMissing($entry);
})->with([
    'projects' => ['projects', 'project', 'name'],
    'areas' => ['areas', 'area', 'name'],
    'resources' => ['resources', 'resource', 'title'],
]);

it('keeps existing records readable editable and recoverable after downgrade', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $user = User::factory()->create();
    PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'focus')->sole())->create();
    $project = $user->projects()->create(['name' => 'Saved work']);
    $this->artisan('plans:assign', ['user_uuid' => $user->uuid, 'plan_slug' => 'free', '--reference' => 'downgrade'])->assertSuccessful();
    Passport::actingAs($user);

    $this->getJson(route('project.show', $project))->assertOk();
    $this->putJson(route('project.update', $project), ['name' => 'Edited work'])->assertOk();
    $this->postJson(route('project.archive', $project))->assertOk();
    $this->postJson(route('project.restore', $project))->assertOk();
    $this->postJson(route('project.store'), ['name' => 'New work'])->assertForbidden();

    $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Edited work', 'archived_at' => null]);
});

it('denies resource creation before storing attachments tags or associations', function (): void {
    Storage::fake('local');
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 10, 'areas' => 5, 'resources' => 0]]);
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('resource.store'), [
        'title' => 'Denied upload', 'links' => ['https://example.com/source'], 'tag_names' => ['New tag'],
        'files' => [UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')],
    ])->assertForbidden()->assertJsonPath('meta.feature', 'resources');

    $this->assertDatabaseEmpty('resources');
    $this->assertDatabaseEmpty('resource_attachments');
    $this->assertDatabaseEmpty('resource_tags');
    Storage::disk('local')->assertDirectoryEmpty('resources');
});

it('denies area creation before storing its background image', function (): void {
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 10, 'areas' => 0, 'resources' => 100]]);
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('area.store'), [
        'name' => 'Denied area', 'background_image' => UploadedFile::fake()->create('background.png', 1, 'image/png'),
    ])->assertForbidden()->assertJsonPath('meta.feature', 'areas');

    $this->assertDatabaseEmpty('areas');
    Storage::disk('public')->assertDirectoryEmpty('areas/backgrounds');
});

it('allows attachment growth and resource linking while over Core quotas', function (): void {
    Storage::fake('local');
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Saved resource']);
    $project = $user->projects()->create(['name' => 'Saved project']);
    Passport::actingAs($user);

    $this->postJson(route('resource.attachments.store', $resource), [
        'links' => ['https://example.com/source'],
        'files' => [UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')],
    ])->assertOk()->assertJsonCount(2, 'data.attachments');
    $this->postJson(route('project.resources.store', $project), ['resource_uuids' => [$resource->uuid]])->assertOk();

    $this->assertDatabaseCount('resources', 1);
    $this->assertDatabaseCount('resource_attachments', 2);
    $this->assertDatabaseHas('project_resource', ['project_id' => $project->id, 'resource_id' => $resource->id]);
});

it('isolates quota usage and ignores forged owner or entitlement fields', function (): void {
    $this->seed(PlanSeeder::class);
    $other = User::factory()->create();
    createQuotaItems($other, 'projects', 10);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson(route('project.store'), [
        'name' => 'Owned work', 'user_id' => $other->id, 'plan_slug' => 'clarity',
        'grant_type' => 'lifetime', 'limits' => ['projects' => null],
    ])->assertCreated();

    $this->assertDatabaseHas('projects', ['user_id' => $user->id, 'name' => 'Owned work']);
    expect($other->projects()->count())->toBe(10);
    $this->assertDatabaseEmpty('plan_assignments');
});

it('fails closed for malformed persisted Core definitions', function (mixed $limit): void {
    $this->seed(PlanSeeder::class);
    $limits = ['areas' => 5, 'resources' => 100];
    if ($limit !== 'missing') {
        $limits['projects'] = $limit;
    }
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => $limits]);
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('project.store'), ['name' => 'Rejected work'])->assertStatus(500);

    $this->assertDatabaseEmpty('projects');
    $this->assertDatabaseEmpty('boards');
})->with(['missing key' => ['missing'], 'string' => ['unlimited'], 'negative' => [-1], 'boolean' => [false]]);

it('allows Core creation beyond the cap when enforcement is disabled', function (
    string $routeName, string $field, string $table,
): void {
    config(['plans.enforcement_enabled' => false]);
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson(route($routeName), [$field => 'Unlimited work'])->assertCreated();

    $this->assertDatabaseHas($table, ['user_id' => $user->id, $field => 'Unlimited work']);
})->with([
    ['project.store', 'name', 'projects'],
    ['area.store', 'name', 'areas'],
    ['resource.store', 'title', 'resources'],
]);

it('preserves security and input validation while enforcement is disabled', function (): void {
    config(['plans.enforcement_enabled' => false]);
    $this->postJson(route('project.store'), ['name' => 'Guest work'])->assertUnauthorized();
    $user = User::factory()->create();
    $foreign = User::factory()->create()->projects()->create(['name' => 'Private project']);
    Passport::actingAs($user);

    $this->postJson(route('project.store'), [])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->putJson(route('project.update', $foreign), ['name' => 'Intrusion'])->assertNotFound();

    $this->assertDatabaseHas('projects', ['id' => $foreign->id, 'name' => 'Private project']);
});

it('keeps every utility unrestricted even when Core and legacy limits are exhausted', function (string $slug): void {
    $this->seed(PlanSeeder::class);
    $plan = Plan::where('slug', $slug)->sole();
    $plan->updateOrFail(['limits' => array_fill_keys([
        'projects', 'areas', 'resources', 'kanban_boards', 'kanban_tasks',
        'reminders', 'notes', 'journal_entries', 'pomodoro', 'attachments_mb',
    ], 0)]);
    $user = User::factory()->create();
    if ($slug !== 'free') {
        PlanAssignment::factory()->for($user)->for($plan)->create();
    }
    $project = $user->projects()->create(['name' => 'Existing project']);
    Passport::actingAs($user);

    for ($index = 0; $index < 11; $index++) {
        $this->postJson(route('board.store'), ['name' => "Board {$index}"])->assertCreated();
        $this->postJson(route('calendar.plans.store'), [
            'title' => "Reminder {$index}", 'date' => now()->addDay()->toDateString(), 'time' => '12:00',
            'timezone' => 'UTC', 'is_all_day' => false, 'reminder_offset_minutes' => 60,
        ])->assertCreated();
    }
    $this->postJson(route('focus.sessions.store'), ['type' => 'short_break'])->assertCreated();
    $this->postJson(route('habits.store'), ['name' => 'Walk', 'frequency' => 'daily'])->assertCreated();
    $this->postJson(route('notes.store'), ['title' => 'Note', 'content' => 'Words'])->assertCreated();
    $content = '{"version":1,"blocks":[{"type":"paragraph","content":"Words"}]}';
    $this->postJson(route('journal.store'), ['title' => 'Reflection', 'content' => $content])->assertCreated();
    $this->postJson(route('letters.store'), ['title' => 'Letter', 'content' => $content])->assertCreated();
    $boardUuid = $this->postJson(route('project.boards.store', $project), ['name' => 'Project board'])->assertCreated()->json('data.uuid');
    $board = $project->boards()->where('uuid', $boardUuid)->sole();
    $this->postJson(route('project.boards.tasks.store', [$project, $board]), ['title' => 'Task'])->assertCreated();

    $this->assertDatabaseCount('boards', 12);
    $this->assertDatabaseCount('calendar_plans', 11);
    $this->assertDatabaseCount('focus_sessions', 1);
    $this->assertDatabaseCount('habits', 1);
    $this->assertDatabaseCount('notes', 1);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('letters', 1);
    $this->assertDatabaseCount('board_tasks', 1);
})->with(['free', 'focus', 'clarity']);
