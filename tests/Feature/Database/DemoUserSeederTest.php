<?php

use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\BoardLabel;
use App\Models\BoardTask;
use App\Models\Goal;
use App\Models\HabitCheckIn;
use App\Models\ResourceTag;
use App\Models\User;
use App\Services\Plan\PlanEntitlementService;
use App\Services\Plan\PlanQuotaService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('creates exactly three verified demo profiles with their explicit effective plans', function (): void {
    $this->travelTo('2026-10-10T04:00:00Z');
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('users', 3);
    $this->assertDatabaseCount('plans', 3);
    $this->assertDatabaseCount('plan_assignments', 3);
    $this->assertDatabaseCount('oauth_clients', 1);
    foreach ([
        'free' => ['name' => 'Free', 'grant' => PlanGrantType::FREE, 'expiry' => null, 'limits' => ['projects' => 10, 'areas' => 5, 'resources' => 100], 'usage' => ['projects' => 0, 'areas' => 0, 'resources' => 0]],
        'focus' => ['name' => 'Focus', 'grant' => PlanGrantType::RECURRING, 'expiry' => '2027-10-10T04:00:00.000000Z', 'limits' => ['projects' => 50, 'areas' => 20, 'resources' => 1000], 'usage' => ['projects' => 9, 'areas' => 8, 'resources' => 4]],
        'clarity' => ['name' => 'Clarity', 'grant' => PlanGrantType::RECURRING, 'expiry' => '2026-11-10T04:00:00.000000Z', 'limits' => ['projects' => null, 'areas' => null, 'resources' => null], 'usage' => ['projects' => 9, 'areas' => 8, 'resources' => 4]],
    ] as $slug => $expected) {
        $user = User::query()->where('email', $slug.'@example.com')->sole();
        $this->assertSame($expected['name'], $user->first_name);
        $this->assertSame('Demo', $user->last_name);
        $this->assertSame($slug.'user', $user->username);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password', $user->getRawOriginal('password')));
        $assignment = $user->planAssignments()->with('plan')->sole();
        $this->assertSame($slug, $assignment->plan->slug);
        $this->assertSame(PlanAssignmentStatus::ACTIVE, $assignment->status);
        $this->assertSame($expected['grant'], $assignment->grant_type);
        $this->assertSame('2026-10-10T04:00:00.000000Z', $assignment->starts_at->toISOString());
        $this->assertSame($expected['expiry'], $assignment->ends_at?->toISOString());
        $this->assertSame('demo', $assignment->source);
        $this->assertSame($slug.'-demo', $assignment->source_reference);
        $entitlements = app(PlanEntitlementService::class)->resolve($user);
        $this->assertSame(['slug' => $slug, 'name' => $expected['name']], $entitlements['plan']);
        $this->assertSame($expected['grant']->value, $entitlements['grant_type']);
        $this->assertSame($expected['expiry'], $entitlements['expires_at']);
        $this->assertSame($expected['limits'], $entitlements['limits']);
        Passport::actingAs($user);
        $this->getJson(route('subscription.show'))->assertOk()
            ->assertJsonPath('data.plan.slug', $slug)
            ->assertJsonPath('data.grant_type', $expected['grant']->value)
            ->assertJsonPath('data.expires_at', $expected['expiry'])
            ->assertJsonPath('data.limits', $expected['limits'])
            ->assertJsonPath('data.usage', $expected['usage']);
    }
    $this->assertDatabaseEmpty('jobs');
    $this->assertDatabaseEmpty('notifications');
    $this->assertDatabaseEmpty('letter_exports');
});

it('gives a new Clarity demo one calendar month in UTC with month-end clamping', function (): void {
    $this->travelTo('2027-01-31T23:30:00Z');
    Storage::fake('public');
    Storage::fake('local');
    User::factory()->create(['email' => 'free@example.com']);
    User::factory()->create(['email' => 'focus@example.com']);

    $this->seed(DemoUserSeeder::class);

    $clarity = User::query()->where('email', 'clarity@example.com')->sole();
    $assignment = $clarity->planAssignments()->sole();
    $this->assertSame(PlanGrantType::RECURRING, $assignment->grant_type);
    $this->assertSame('2027-01-31T23:30:00.000000Z', $assignment->starts_at->toISOString());
    $this->assertSame('2027-02-28T23:30:00.000000Z', $assignment->ends_at->toISOString());
    $this->assertSame(['projects' => null, 'areas' => null, 'resources' => null], app(PlanEntitlementService::class)->resolve($clarity)['limits']);
});

it('preserves an expired Clarity demo assignment and edited content when seeding again', function (): void {
    $this->travelTo('2026-10-10T04:00:00Z');
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DemoUserSeeder::class);
    $clarity = User::query()->where('email', 'clarity@example.com')->sole();
    $clarity->update(['first_name' => 'My Clarity', 'password' => 'my edited password']);
    $area = $clarity->areas()->where('slug', 'health')->sole();
    $area->update(['name' => 'My health', 'archived_at' => now()]);
    $assignment = $clarity->planAssignments()->sole();
    $userState = $clarity->fresh()->getRawOriginal();
    $areaState = $area->fresh()->getRawOriginal();
    $assignmentState = $assignment->getRawOriginal();
    $localPaths = Storage::disk('local')->allFiles();
    $publicPaths = Storage::disk('public')->allFiles();
    $this->travelTo('2026-11-10T04:00:00Z');

    $this->seed(DemoUserSeeder::class);

    $this->assertDatabaseCount('users', 3);
    $this->assertDatabaseCount('plan_assignments', 3);
    $this->assertSame($userState, $clarity->fresh()->getRawOriginal());
    $this->assertSame($areaState, $area->fresh()->getRawOriginal());
    $this->assertSame($assignmentState, $assignment->fresh()->getRawOriginal());
    $this->assertSame($localPaths, Storage::disk('local')->allFiles());
    $this->assertSame($publicPaths, Storage::disk('public')->allFiles());
    $entitlements = app(PlanEntitlementService::class)->resolve($clarity);
    $this->assertSame('free', $entitlements['plan']['slug']);
    $this->assertSame(['projects' => 9, 'areas' => 8, 'resources' => 4], app(PlanQuotaService::class)->usage($clarity));
});

it('leaves the Free demo workspace empty including archive trash and focus settings', function (): void {
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(DemoUserSeeder::class);

    $free = User::query()->where('email', 'free@example.com')->sole();
    foreach (['areas', 'projects', 'resources', 'boards', 'habits', 'notes', 'focusTasks', 'journalEntries', 'letters', 'calendarPlans'] as $relation) {
        $this->assertSame(0, $free->{$relation}()->withTrashed()->count(), $relation.' must stay empty for the Free demo.');
    }
    $this->assertSame(0, $free->trashEntries()->count());
    $this->assertSame(0, $free->focusSessions()->count());
    $this->assertSame(0, $free->focusSetting()->count());
    $this->assertSame(0, $free->notifications()->count());
    $this->assertSame(0, ResourceTag::query()->where('user_id', $free->id)->count());
    Storage::disk('public')->assertDirectoryEmpty('areas/backgrounds/seed/'.$free->uuid);
    Passport::actingAs($free);
    $this->getJson(route('area.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('project.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('resource.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson(route('trash.index'))->assertOk()->assertJsonPath('data.total', 0);
    $this->assertSame(0, $free->focusSetting()->count());
});

it('populates paid demo Core states and interconnected utilities with the approved counts', function (string $slug): void {
    $this->travelTo('2026-10-10T04:00:00Z');
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(DemoUserSeeder::class);

    $user = User::query()->where('email', $slug.'@example.com')->sole();
    foreach (['areas' => [7, 9], 'projects' => [8, 10], 'resources' => [3, 5]] as $relation => [$active, $total]) {
        $this->assertSame($active, $user->{$relation}()->whereNull('archived_at')->count(), $relation.' active count');
        $this->assertSame(1, $user->{$relation}()->whereNotNull('archived_at')->count(), $relation.' archive count');
        $this->assertSame(1, $user->{$relation}()->onlyTrashed()->count(), $relation.' Trash count');
        $this->assertSame($total, $user->{$relation}()->withTrashed()->count(), $relation.' total count');
    }
    $this->assertSame(['projects' => 9, 'areas' => 8, 'resources' => 4], app(PlanQuotaService::class)->usage($user));
    $entries = $user->trashEntries()->get();
    $this->assertCount(3, $entries);
    $this->assertSame(['area', 'project', 'resource'], $entries->pluck('item_type')->sort()->values()->all());
    foreach ($entries as $entry) {
        $this->assertSame('2026-11-09T04:00:00.000000Z', $entry->expires_at->toISOString());
    }
    $this->assertSame(5, Goal::query()->whereIn('area_id', $user->areas()->pluck('id'))->count());
    $this->assertSame(5, $user->habits()->count());
    $this->assertSame(14, HabitCheckIn::query()->whereIn('habit_id', $user->habits()->pluck('id'))->count());
    $this->assertSame(31, BoardTask::query()->whereHas('board', fn (Builder $query): Builder => $query
        ->where('user_id', $user->id)->whereNotNull('context_type'))->count());
    $standalone = $user->boards()->whereNull('context_type')->whereNull('context_id')->sole();
    $this->assertSame(4, $standalone->tasks()->count());
    $this->assertSame(2, BoardLabel::query()->whereHas('board', fn (Builder $query): Builder => $query->where('user_id', $user->id))->count());
    $this->assertSame(4, $user->notes()->whereNotNull('area_id')->count());
    $this->assertSame(3, $user->standaloneNotes()->count());
    $root = $user->standaloneNotes()->where('title', 'Weekly planning')->sole();
    $this->assertSame($root->id, $user->standaloneNotes()->where('title', 'Review checklist')->sole()->parent_id);
    $this->assertSame(3, $user->focusTasks()->count());
    $this->assertSame(1, $user->focusTasks()->whereNotNull('completed_at')->count());
    $this->assertSame(1, $user->focusSetting()->count());
    $this->assertSame(2, $user->focusSessions()->count());
    $this->assertSame(2, $user->focusSessions()->where('status', 'completed')->where('remaining_seconds', 0)->whereNull('ends_at')->count());
    $this->assertSame(3, $user->journalEntries()->count());
    $reflection = $user->journalEntries()->whereNotNull('focus_session_id')->sole();
    $this->assertSame($user->id, $reflection->focusSession->user_id);
    $this->assertSame('A clear next step made this session productive.', $reflection->content_text);
    $this->assertSame(2, $user->letters()->count());
    foreach ($user->letters()->get() as $letter) {
        $this->assertGreaterThan(0, $letter->word_count);
        $this->assertGreaterThan(0, $letter->read_time_minutes);
        $this->assertNotEmpty(json_decode($letter->content, true, flags: JSON_THROW_ON_ERROR)['blocks']);
    }
    $this->assertSame(3, $user->calendarPlans()->count());
    foreach ($user->calendarPlans()->get() as $event) {
        $this->assertSame('Asia/Manila', $event->timezone);
        $this->assertNull($event->reminder_offset_minutes);
        $this->assertNull($event->remind_at);
        $this->assertNull($event->reminder_token);
        $this->assertNull($event->notified_at);
        $this->assertNull($event->email_sent_at);
        if ($event->area_id !== null) {
            $this->assertSame($user->id, $event->area->user_id);
        }
        if ($event->project_id !== null) {
            $this->assertSame($user->id, $event->project->user_id);
        }
    }
    $this->assertSame(5, ResourceTag::query()->where('user_id', $user->id)->count());
    $this->assertDatabaseEmpty('jobs');
    $this->assertDatabaseEmpty('notifications');
})->with(['Focus' => ['focus'], 'Clarity' => ['clarity']]);

it('makes paid demo utilities and modern resource fixtures readable only by their owner', function (string $slug): void {
    $this->travelTo('2026-10-10T04:00:00Z');
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DemoUserSeeder::class);
    $user = User::query()->where('email', $slug.'@example.com')->sole();
    Passport::actingAs($user);

    foreach (['note' => 3, 'link' => 1, 'file' => 1, 'image' => 1] as $type => $count) {
        $this->getJson(route('resource.index', ['type' => $type]))->assertOk()->assertJsonPath('data.total', $count);
    }
    $guide = $user->resources()->where('title', 'Beginner 10K Training Guide')->sole();
    $this->getJson(route('resource.index', ['search' => 'recovery days']))->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.uuid', $guide->uuid);
    $tag = ResourceTag::query()->where('user_id', $user->id)->where('normalized_name', 'learning')->sole();
    $this->getJson(route('resource.index', ['tag_uuid' => $tag->uuid]))->assertOk()->assertJsonPath('data.total', 2);
    foreach ([
        'Beginner 10K Training Guide' => ['link', 'note'],
        'Building a Meaningful Career' => ['file', 'note'],
        'Monthly Reflection Template' => ['image', 'note'],
    ] as $title => $types) {
        $resource = $user->resources()->where('title', $title)->sole();
        $this->getJson(route('resource.show', $resource))->assertOk()
            ->assertJsonPath('data.types', $types)->assertJsonPath('data.content.type', 'doc');
        $this->assertNotEmpty($resource->content_text);
        $this->assertNotEmpty($resource->tags);
        $this->assertNotEmpty($resource->areas);
        $this->assertNotEmpty($resource->projects);
        foreach ($resource->areas->concat($resource->projects)->concat($resource->tags) as $related) {
            $this->assertSame($user->id, $related->user_id);
        }
        foreach ($resource->attachments()->whereNotNull('path')->get() as $attachment) {
            $this->assertStringStartsWith('resources/'.$resource->uuid.'/', $attachment->path);
            $this->assertGreaterThan(0, $attachment->size);
            Storage::disk('local')->assertExists($attachment->path);
            $this->getJson(route('resource.attachments.show', [$resource, $attachment->uuid]))
                ->assertOk()->assertDownload($attachment->original_name);
        }
    }
    $board = $user->boards()->whereNull('context_type')->sole();
    $this->getJson(route('board.show', $board))->assertOk()->assertJsonCount(2, 'data.labels')->assertJsonCount(4, 'data.stages');
    $note = $user->standaloneNotes()->where('title', 'Review checklist')->sole();
    $this->getJson(route('notes.show', $note))->assertOk()->assertJsonPath('data.parent_uuid', $note->parent->uuid);
    $reflection = $user->journalEntries()->whereNotNull('focus_session_id')->sole();
    $this->getJson(route('journal.show', $reflection))->assertOk()
        ->assertJsonPath('data.source.session_uuid', $reflection->focusSession->uuid)
        ->assertJsonPath('data.content_preview', 'A clear next step made this session productive.');
    $letter = $user->letters()->where('title', 'A letter to my future self')->sole();
    $this->getJson(route('letters.show', $letter))->assertOk()->assertJsonPath('data.author.handle', '@'.$slug.'user');
    $this->getJson(route('focus.show', ['timezone' => 'Asia/Manila']))->assertOk()
        ->assertJsonPath('data.active_session', null)->assertJsonCount(2, 'data.tasks')
        ->assertJsonPath('data.today.completed_focus_sessions', 1)->assertJsonPath('data.today.focused_seconds', 1500);
    $event = $user->calendarPlans()->where('title', 'Portfolio review')->sole();
    $this->getJson(route('calendar.plans.index', [
        'start_date' => '2026-10-10', 'end_date' => '2026-10-17', 'timezone' => 'Asia/Manila',
    ]))->assertOk()->assertJsonCount(3, 'data');

    $other = User::query()->where('email', $slug === 'focus' ? 'clarity@example.com' : 'focus@example.com')->sole();
    Passport::actingAs($other);

    $this->getJson(route('resource.show', $guide))->assertNotFound();
    $this->getJson(route('board.show', $board))->assertNotFound();
    $this->getJson(route('notes.show', $note))->assertNotFound();
    $this->getJson(route('journal.show', $reflection))->assertNotFound();
    $this->getJson(route('letters.show', $letter))->assertNotFound();
    $this->getJson(route('calendar.plans.show', $event))->assertNotFound();
    $fileResource = $user->resources()->where('title', 'Building a Meaningful Career')->sole();
    $file = $fileResource->attachments()->where('kind', 'file')->sole();
    $this->getJson(route('resource.attachments.show', [$fileResource, $file->uuid]))->assertNotFound();
    $this->assertDatabaseEmpty('jobs');
    $this->assertDatabaseEmpty('notifications');
})->with(['Focus' => ['focus'], 'Clarity' => ['clarity']]);

it('restores each paid demo Core Trash entry with capacity while preserving its files', function (string $slug): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DemoUserSeeder::class);
    $user = User::query()->where('email', $slug.'@example.com')->sole();
    $entries = $user->trashEntries()->get();
    $paths = Storage::disk('local')->allFiles();
    $resourceEntry = $entries->where('item_type', 'resource')->sole();
    $other = User::query()->where('email', $slug === 'focus' ? 'clarity@example.com' : 'focus@example.com')->sole();
    Passport::actingAs($other);
    $this->postJson(route('trash.restore', $resourceEntry))->assertNotFound();
    $this->assertModelExists($resourceEntry);
    Passport::actingAs($user);
    $this->getJson(route('trash.index'))->assertOk()->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.data.0.can_restore', true)
        ->assertJsonPath('data.data.1.can_restore', true)
        ->assertJsonPath('data.data.2.can_restore', true);

    foreach ($entries as $entry) {
        $this->postJson(route('trash.restore', $entry))->assertOk()->assertJsonPath('data', null);
        $this->assertModelMissing($entry);
    }

    $this->assertSame(['projects' => 10, 'areas' => 9, 'resources' => 5], app(PlanQuotaService::class)->usage($user));
    $this->assertSame(0, $user->trashEntries()->count());
    $this->assertSame(3, $other->trashEntries()->count());
    $this->assertSame($paths, Storage::disk('local')->allFiles());
    $restored = $user->resources()->where('title', 'Trashed Resource')->sole();
    $attachment = $restored->attachments()->where('kind', 'file')->sole();
    $this->getJson(route('resource.attachments.show', [$restored, $attachment->uuid]))
        ->assertOk()->assertDownload('recoverable-reference.txt');
    Storage::disk('local')->assertExists($attachment->path);
    $this->assertDatabaseEmpty('jobs');
})->with(['Focus' => ['focus'], 'Clarity' => ['clarity']]);

it('purges one paid demos Area and Resource files without affecting the other demos files or data', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DemoUserSeeder::class);
    $focus = User::query()->where('email', 'focus@example.com')->sole();
    $clarity = User::query()->where('email', 'clarity@example.com')->sole();
    $focusArea = $focus->areas()->where('slug', 'health')->sole();
    $clarityArea = $clarity->areas()->where('slug', 'health')->sole();
    $this->assertSame('areas/backgrounds/seed/'.$focus->uuid.'/health.png', $focusArea->background_image);
    $this->assertSame('areas/backgrounds/seed/'.$clarity->uuid.'/health.png', $clarityArea->background_image);
    $clarityPublicPaths = $clarity->areas()->whereNotNull('background_image')->pluck('background_image')->all();
    $clarityLocalPaths = $clarity->resources()->withTrashed()->get()->flatMap(fn ($resource) => $resource->attachments()->withTrashed()->whereNotNull('path')->pluck('path'))->all();
    $focusResource = $focus->resources()->where('title', 'Building a Meaningful Career')->sole();
    $focusLocalPath = $focusResource->attachments()->where('kind', 'file')->sole()->path;
    Passport::actingAs($focus);

    $this->deleteJson(route('area.destroy', $focusArea))->assertOk();
    $areaEntry = $focus->trashEntries()->where('subject_uuid', $focusArea->uuid)->sole();
    $this->deleteJson(route('trash.destroy', $areaEntry))->assertOk();
    $this->deleteJson(route('resource.destroy', $focusResource))->assertOk();
    $resourceEntry = $focus->trashEntries()->where('subject_uuid', $focusResource->uuid)->sole();
    $this->deleteJson(route('trash.destroy', $resourceEntry))->assertOk();

    $this->assertModelMissing($focusArea);
    $this->assertModelMissing($focusResource);
    Storage::disk('public')->assertMissing($focusArea->background_image);
    Storage::disk('local')->assertMissing($focusLocalPath);
    Storage::disk('public')->assertExists($clarityPublicPaths);
    Storage::disk('local')->assertExists($clarityLocalPaths);
    $this->assertSame(['projects' => 9, 'areas' => 8, 'resources' => 4], app(PlanQuotaService::class)->usage($clarity));
    $this->assertSame(3, $clarity->trashEntries()->count());
    $this->assertDatabaseEmpty('jobs');
});

it('initializes only missing profiles and completely preserves an existing matching demo account', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $existing = User::factory()->unverified()->create([
        'first_name' => 'Existing Focus', 'email' => 'focus@example.com', 'username' => 'my-focus', 'password' => 'custom password',
    ]);
    $path = 'areas/backgrounds/my-custom-health.png';
    Storage::disk('public')->put($path, 'existing image');
    $area = $existing->areas()->create(['name' => 'My custom health', 'background_image' => $path, 'archived_at' => now()]);
    $userState = $existing->fresh()->getRawOriginal();
    $areaState = $area->fresh()->getRawOriginal();

    $this->seed(DemoUserSeeder::class);

    $this->assertDatabaseCount('users', 3);
    $this->assertSame($userState, $existing->fresh()->getRawOriginal());
    $this->assertSame($areaState, $area->fresh()->getRawOriginal());
    $this->assertSame(1, $existing->areas()->withTrashed()->count());
    foreach (['projects', 'resources', 'boards', 'habits', 'notes', 'focusTasks', 'journalEntries', 'letters', 'calendarPlans'] as $relation) {
        $this->assertSame(0, $existing->{$relation}()->withTrashed()->count());
    }
    $this->assertSame(0, $existing->planAssignments()->count());
    $this->assertSame(0, $existing->focusSetting()->count());
    $this->assertSame(0, $existing->trashEntries()->count());
    $this->assertSame('existing image', Storage::disk('public')->get($path));
    $free = User::query()->where('email', 'free@example.com')->sole();
    $clarity = User::query()->where('email', 'clarity@example.com')->sole();
    $this->assertTrue($free->hasVerifiedEmail());
    $this->assertTrue($clarity->hasVerifiedEmail());
    $this->assertSame(0, $free->areas()->withTrashed()->count());
    $this->assertSame(9, $clarity->areas()->withTrashed()->count());
    $this->assertDatabaseCount('plan_assignments', 2);
});

it('cleans partially written demo images on rollback and permits retry without touching existing files', function (bool $throws): void {
    Storage::fake('public');
    Storage::fake('local');
    $disk = Storage::disk('public');
    $existing = User::factory()->create();
    $existingPath = 'areas/backgrounds/seed/'.$existing->uuid.'/health.png';
    $disk->put($existingPath, 'existing image');
    $existingArea = $existing->areas()->create(['name' => 'Existing health', 'background_image' => $existingPath]);
    $writes = 0;
    $mock = Mockery::mock(FilesystemAdapter::class);
    $mock->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => $disk->exists($path));
    $mock->shouldReceive('put')->twice()->andReturnUsing(function (string $path, string $contents) use ($disk, &$writes, $throws): bool {
        $disk->put($path, $contents);
        if (++$writes === 2) {
            if ($throws) {
                throw new RuntimeException('Simulated demo image storage failure.');
            }

            return false;
        }

        return true;
    });
    $mock->shouldReceive('delete')->andReturnUsing(fn (string|array $paths): bool => $disk->delete($paths));
    Storage::set('public', $mock);

    try {
        expect(fn () => $this->seed(DemoUserSeeder::class))->toThrow(RuntimeException::class,
            $throws ? 'Simulated demo image storage failure.' : 'Unable to store demo Area image.');
    } finally {
        Storage::set('public', $disk);
    }

    $this->assertDatabaseCount('users', 1);
    $this->assertModelExists($existingArea);
    $this->assertDatabaseEmpty('plan_assignments');
    $this->assertSame([$existingPath], $disk->allFiles());
    $this->assertSame('existing image', $disk->get($existingPath));
    Storage::disk('local')->assertDirectoryEmpty('resources');
    $this->assertDatabaseEmpty('jobs');

    $this->seed(DemoUserSeeder::class);

    $this->assertDatabaseCount('users', 4);
    $this->assertDatabaseCount('plan_assignments', 3);
    $this->assertSame('existing image', $disk->get($existingPath));
    $focus = User::query()->where('email', 'focus@example.com')->sole();
    $clarity = User::query()->where('email', 'clarity@example.com')->sole();
    $this->assertSame(9, $focus->areas()->withTrashed()->count());
    $this->assertSame(9, $clarity->areas()->withTrashed()->count());
})->with(['put returns false after writing' => [false], 'put throws after writing' => [true]]);
