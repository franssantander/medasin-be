<?php

use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Board\BoardService;
use App\Services\Board\BoardTaskService;
use App\Services\Trash\TrashService;
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

it('returns 403 for Core recovery at the cap and restores after capacity is freed', function (string $feature, string $prefix, string $field): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 1, 'areas' => 1, 'resources' => 1]]);
    $user = User::factory()->create();
    createQuotaItems($user, $feature, 1);
    $item = $user->{$feature}()->sole();
    Passport::actingAs($user);

    $this->deleteJson(route($prefix.'.destroy', $item))->assertOk();
    $this->postJson(route($prefix.'.store'), [$field => 'Replacement item'])->assertCreated();
    $replacement = $user->{$feature}()->sole();
    $entry = TrashEntry::where('subject_uuid', $item->uuid)->sole();
    $this->getJson(route('trash.index', ['type' => $prefix]))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $entry->uuid)
        ->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', "The {$feature} limit for your current plan has been reached.");

    $this->postJson(route('trash.restore', $entry))->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('message', "The {$feature} limit for your current plan has been reached.")
        ->assertJsonPath('data', null)
        ->assertJsonPath('status', 403)
        ->assertJsonPath('meta', ['feature' => $feature, 'usage' => 1, 'limit' => 1]);

    $this->assertSoftDeleted($item);
    $this->assertNotSoftDeleted($replacement);
    $this->assertModelExists($entry);
    expect($user->{$feature}()->count())->toBe(1);
    $this->assertDatabaseCount($feature, 2);
    $this->assertDatabaseCount('trash_entries', 1);

    $this->deleteJson(route($prefix.'.destroy', $replacement))->assertOk();
    $this->getJson(route('trash.index', ['type' => $prefix, 'search' => $item->{$field}]))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $entry->uuid)
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);
    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertNotSoftDeleted($item);
    $this->assertSoftDeleted($replacement);
    $this->assertModelMissing($entry);
    expect($user->{$feature}()->count())->toBe(1);
    $this->assertDatabaseCount('trash_entries', 1);
})->with([
    'projects' => ['projects', 'project', 'name'],
    'areas' => ['areas', 'area', 'name'],
    'resources' => ['resources', 'resource', 'title'],
]);

it('returns 403 for Core recovery with a zero limit or existing over-cap usage', function (
    string $feature, string $prefix, int $limit, int $usage,
): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => $limit, 'areas' => $limit, 'resources' => $limit]]);
    $user = User::factory()->create();
    createQuotaItems($user, $feature, 1, [$feature === 'resources' ? 'title' : 'name' => 'Trashed item']);
    $item = $user->{$feature}()->sole();
    Passport::actingAs($user);
    $this->deleteJson(route($prefix.'.destroy', $item))->assertOk();
    createQuotaItems($user, $feature, $usage);
    $entry = TrashEntry::where('subject_uuid', $item->uuid)->sole();

    $this->getJson(route('trash.index', ['type' => $prefix]))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', "The {$feature} limit for your current plan has been reached.");
    $this->postJson(route('trash.restore', $entry))->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('meta', ['feature' => $feature, 'usage' => $usage, 'limit' => $limit]);

    $this->assertSoftDeleted($item);
    $this->assertModelExists($entry);
    expect($user->{$feature}()->count())->toBe($usage);
    $this->assertDatabaseCount($feature, $usage + 1);
})->with([
    'projects with zero allowance' => ['projects', 'project', 0, 0],
    'areas with zero allowance' => ['areas', 'area', 0, 0],
    'resources with zero allowance' => ['resources', 'resource', 0, 0],
    'projects already over cap' => ['projects', 'project', 1, 2],
    'areas already over cap' => ['areas', 'area', 1, 2],
    'resources already over cap' => ['resources', 'resource', 1, 2],
]);

it('rechecks the final recovery slot after an earlier listing allowed two entries', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 1]]);
    $user = User::factory()->create();
    $first = $user->resources()->create(['title' => 'First reference']);
    $second = $user->resources()->create(['title' => 'Second reference']);
    Passport::actingAs($user);
    $this->deleteJson(route('resource.destroy', $first))->assertOk();
    $this->deleteJson(route('resource.destroy', $second))->assertOk();
    $firstEntry = TrashEntry::where('subject_uuid', $first->uuid)->sole();
    $secondEntry = TrashEntry::where('subject_uuid', $second->uuid)->sole();
    $this->getJson(route('trash.index', ['type' => 'resource']))->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.1.can_restore', true);

    $this->postJson(route('trash.restore', $firstEntry))->assertOk();
    $this->postJson(route('trash.restore', $secondEntry))->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('meta', ['feature' => 'resources', 'usage' => 1, 'limit' => 1]);

    $this->assertNotSoftDeleted($first);
    $this->assertSoftDeleted($second);
    $this->assertModelMissing($firstEntry);
    $this->assertModelExists($secondEntry);
    expect($user->resources()->count())->toBe(1);
});

it('requires capacity for archived Core recovery and preserves the archive state', function (
    string $feature, string $prefix, string $field,
): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 1, 'areas' => 1, 'resources' => 1]]);
    $user = User::factory()->create();
    createQuotaItems($user, $feature, 1, ['archived_at' => '2026-09-01 12:00:00']);
    $item = $user->{$feature}()->sole();
    $entry = app(TrashService::class)->delete($user, $item, $prefix, $item->{$field});
    createQuotaItems($user, $feature, 1, [$field => 'Replacement item']);
    $replacement = $user->{$feature}()->sole();
    Passport::actingAs($user);

    $this->postJson(route('trash.restore', $entry))->assertForbidden()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
        ->assertJsonPath('meta', ['feature' => $feature, 'usage' => 1, 'limit' => 1]);

    $this->assertSoftDeleted($item);
    $this->assertModelExists($entry);
    $this->deleteJson(route($prefix.'.destroy', $replacement))->assertOk();
    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertDatabaseHas($feature, ['id' => $item->id, 'deleted_at' => null, 'archived_at' => '2026-09-01 12:00:00']);
    $this->assertModelMissing($entry);
    expect($user->{$feature}()->count())->toBe(1);
})->with([
    'projects' => ['projects', 'project', 'name'],
    'areas' => ['areas', 'area', 'name'],
    'resources' => ['resources', 'resource', 'title'],
]);

it('restores only the selected Core feature when other features are over quota', function (
    string $feature, string $prefix,
): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => [
        'projects' => $feature === 'projects' ? 1 : 0,
        'areas' => $feature === 'areas' ? 1 : 0,
        'resources' => $feature === 'resources' ? 1 : 0,
    ]]);
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Related area']);
    $project = $user->projects()->create(['name' => 'Related project']);
    $project->area()->associate($area);
    $project->saveOrFail();
    $resource = $user->resources()->create(['title' => 'Related resource']);
    $resource->areas()->attach($area);
    $resource->projects()->attach($project);
    $item = $user->{$feature}()->sole();
    Passport::actingAs($user);
    $this->deleteJson(route($prefix.'.destroy', $item))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $item->uuid)->sole();

    $this->getJson(route('trash.index', ['type' => $prefix]))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);
    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertNotSoftDeleted($item);
    $this->assertModelMissing($entry);
    expect($user->projects()->count())->toBe(1)
        ->and($user->areas()->count())->toBe(1)
        ->and($user->resources()->count())->toBe(1);
    $this->assertDatabaseHas('projects', ['id' => $project->id, 'area_id' => $area->id]);
    $this->assertDatabaseHas('area_resource', ['area_id' => $area->id, 'resource_id' => $resource->id]);
    $this->assertDatabaseHas('project_resource', ['project_id' => $project->id, 'resource_id' => $resource->id]);
})->with([
    'projects' => ['projects', 'project'],
    'areas and their unchanged Core children' => ['areas', 'area'],
    'resources and their associations' => ['resources', 'resource'],
]);

it('keeps parent availability ahead of a Core recovery quota denial', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 1, 'resources' => 0]]);
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Parent area']);
    $project = $user->projects()->create(['name' => 'Child project']);
    $project->area()->associate($area);
    $project->saveOrFail();
    Passport::actingAs($user);
    $this->deleteJson(route('project.destroy', $project))->assertOk();
    $this->deleteJson(route('area.destroy', $area))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $project->uuid)->sole();

    $this->getJson(route('trash.index', ['type' => 'project']))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', false)
        ->assertJsonPath('data.data.0.restore_block_reason', 'Restore the parent item first before restoring this item.');
    $this->postJson(route('trash.restore', $entry))->assertConflict()
        ->assertJsonPath('message', 'Restore the parent item first before restoring this item.')
        ->assertJsonMissingPath('code');

    $this->assertSoftDeleted($project);
    $this->assertSoftDeleted($area);
    $this->assertModelExists($entry);
});

it('returns 410 and purges an expired Core entry before considering its quota', function (): void {
    $this->travelTo('2026-10-05T08:00:00Z');
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Expired reference']);
    Passport::actingAs($user);
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $resource->uuid)->sole();
    $this->travel(30)->days();

    $this->postJson(route('trash.restore', $entry))->assertGone()
        ->assertJsonPath('message', 'This item has expired and was permanently deleted.')
        ->assertJsonMissingPath('code');

    $this->assertModelMissing($entry);
    $this->assertDatabaseMissing('resources', ['id' => $resource->id]);
    $this->getJson(route('trash.index', ['type' => 'resource']))->assertOk()->assertJsonPath('data.total', 0);
});

it('returns 404 for another owners Core recovery before evaluating quota', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $owner = User::factory()->create();
    $resource = $owner->resources()->create(['title' => 'Private reference']);
    Passport::actingAs($owner);
    $this->deleteJson(route('resource.destroy', $resource))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $resource->uuid)->sole();
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('trash.restore', $entry))->assertNotFound()->assertJsonMissingPath('code');

    $this->assertSoftDeleted($resource);
    $this->assertModelExists($entry);
    $this->getJson(route('trash.index'))->assertOk()->assertJsonPath('data.total', 0);
});

it('allows Core recovery with effective unlimited entitlements', function (
    string $mode, string $feature, string $prefix,
): void {
    $this->seed(PlanSeeder::class);
    $user = User::factory()->create();
    if ($mode === 'disabled') {
        config(['plans.enforcement_enabled' => false]);
        Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    } elseif ($mode === 'lifetime') {
        $plan = Plan::where('slug', 'clarity')->sole();
        $plan->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
        PlanAssignment::factory()->for($user)->for($plan)->lifetime()->create();
    } else {
        PlanAssignment::factory()->for($user)->for(Plan::where('slug', 'clarity')->sole())->create();
    }
    createQuotaItems($user, $feature, 1, [$feature === 'resources' ? 'title' : 'name' => 'Recovered item']);
    $item = $user->{$feature}()->sole();
    Passport::actingAs($user);
    $this->deleteJson(route($prefix.'.destroy', $item))->assertOk();
    createQuotaItems($user, $feature, 1);
    $entry = TrashEntry::where('subject_uuid', $item->uuid)->sole();

    $this->getJson(route('trash.index', ['type' => $prefix]))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);
    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertNotSoftDeleted($item);
    $this->assertModelMissing($entry);
    expect($user->{$feature}()->count())->toBe(2);
})->with([
    'Clarity projects' => ['clarity', 'projects', 'project'],
    'Clarity areas' => ['clarity', 'areas', 'area'],
    'Clarity resources' => ['clarity', 'resources', 'resource'],
    'lifetime projects' => ['lifetime', 'projects', 'project'],
    'lifetime areas' => ['lifetime', 'areas', 'area'],
    'lifetime resources' => ['lifetime', 'resources', 'resource'],
    'disabled projects' => ['disabled', 'projects', 'project'],
    'disabled areas' => ['disabled', 'areas', 'area'],
    'disabled resources' => ['disabled', 'resources', 'resource'],
]);

it('restores grouped boards and notes without consuming Core quota', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $user = User::factory()->create();
    createQuotaItems($user, 'projects', 1);
    createQuotaItems($user, 'areas', 1);
    createQuotaItems($user, 'resources', 1);
    $board = app(BoardService::class)->createStandalone($user);
    app(BoardService::class)->createStandalone($user);
    $task = app(BoardTaskService::class)->create($user, $board, ['title' => 'Saved task']);
    $note = $user->standaloneNotes()->create(['title' => 'Root note', 'content' => '']);
    $child = $user->standaloneNotes()->create(['title' => 'Child note', 'parent_id' => $note->id, 'content' => '']);
    Passport::actingAs($user);
    $this->deleteJson(route('board.destroy', $board))->assertOk();
    $this->deleteJson(route('notes.destroy', $note))->assertOk();
    $boardEntry = TrashEntry::where('subject_uuid', $board->uuid)->sole();
    $noteEntry = TrashEntry::where('subject_uuid', $note->uuid)->sole();

    $this->getJson(route('trash.index'))->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.group_size', 2)
        ->assertJsonPath('data.data.1.can_restore', true)
        ->assertJsonPath('data.data.1.group_size', 2);
    $this->postJson(route('trash.restore', $boardEntry))->assertOk();
    $this->postJson(route('trash.restore', $noteEntry))->assertOk();

    $this->assertNotSoftDeleted($board);
    $this->assertNotSoftDeleted($task);
    $this->assertNotSoftDeleted($note);
    $this->assertNotSoftDeleted($child);
    $this->assertDatabaseEmpty('trash_entries');
    expect($user->projects()->count())->toBe(1)
        ->and($user->areas()->count())->toBe(1)
        ->and($user->resources()->count())->toBe(1);
});

it('restores attachment files while the parent resource is over quota', function (): void {
    Storage::fake('local');
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 0, 'areas' => 0, 'resources' => 0]]);
    $user = User::factory()->create();
    $resource = $user->resources()->create(['title' => 'Existing reference']);
    Storage::disk('local')->put('resources/recoverable.txt', 'Retained attachment');
    $attachment = $resource->attachments()->create([
        'kind' => 'file', 'path' => 'resources/recoverable.txt', 'original_name' => 'recoverable.txt',
    ]);
    Passport::actingAs($user);
    $this->deleteJson(route('resource.attachments.destroy', [$resource, $attachment->uuid]))->assertOk();
    $entry = TrashEntry::where('subject_uuid', $attachment->uuid)->sole();

    $this->getJson(route('trash.index', ['type' => 'resource_attachment']))->assertOk()
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.0.restore_block_reason', null);
    $this->postJson(route('trash.restore', $entry))->assertOk();

    $this->assertNotSoftDeleted($attachment);
    $this->assertModelMissing($entry);
    Storage::disk('local')->assertExists('resources/recoverable.txt');
    expect($user->resources()->count())->toBe(1);
});

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
