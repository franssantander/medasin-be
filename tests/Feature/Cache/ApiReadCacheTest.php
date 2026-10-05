<?php

namespace Tests\Feature\Cache;

use App\Enum\LetterExportStatus;
use App\Models\CalendarPlan;
use App\Models\JournalEntry;
use App\Models\Letter;
use App\Models\LetterExport;
use App\Models\Plan;
use App\Models\ResourceTag;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Board\BoardService;
use App\Services\Board\BoardTaskService;
use App\Services\Trash\TrashService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ApiReadCacheTest extends TestCase
{
    use DatabaseMigrations;

    /** @return array<string, array{string, array<string, string>}> */
    public static function readEndpoints(): array
    {
        return [
            'projects' => ['project.index', []],
            'areas' => ['area.index', []],
            'resources' => ['resource.index', []],
            'resource tags' => ['resource.tags', []],
            'notes' => ['notes.index', []],
            'note tree' => ['notes.tree', []],
            'letters' => ['letters.index', []],
            'journal' => ['journal.index', []],
            'habits' => ['habits.index', []],
            'habit calendar' => ['habits.calendar', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']],
            'home' => ['home.show', ['timezone' => 'UTC']],
            'search' => ['search.index', ['q' => 'sample']],
            'focus tasks' => ['focus.tasks.index', []],
            'linkable tasks' => ['focus.linkable-tasks', []],
            'calendar' => ['calendar.plans.index', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => 'UTC']],
            'notifications' => ['notifications.index', []],
            'public plans' => ['plan.index', []],
        ];
    }

    #[DataProvider('readEndpoints')]
    public function test_repeated_reads_keep_the_json_and_skip_domain_queries(string $route, array $query): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create());
        $first = $this->getJson(route($route, $query))->assertOk();
        DB::enableQueryLog();

        $second = $this->getJson(route($route, $query))->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame([], $this->domainQueries());
    }

    public function test_edits_refresh_lists_details_home_and_search_immediately(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Sample before']);
        Passport::actingAs($user);
        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'Sample before');
        $this->getJson(route('project.show', $project))->assertJsonPath('data.name', 'Sample before');
        $this->getJson(route('home.show'))->assertJsonPath('data.projects.0.name', 'Sample before');
        $this->getJson(route('search.index', ['q' => 'Sample']))->assertJsonPath('data.groups.0.items.0.title', 'Sample before');

        $this->putJson(route('project.update', $project), ['name' => 'Sample after'])->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Sample after']);
        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'Sample after');
        $this->getJson(route('project.show', $project))->assertJsonPath('data.name', 'Sample after');
        $this->getJson(route('home.show'))->assertJsonPath('data.projects.0.name', 'Sample after');
        $this->getJson(route('search.index', ['q' => 'Sample']))->assertJsonPath('data.groups.0.items.0.title', 'Sample after');
    }

    public function test_cached_reads_still_enforce_ownership_and_validation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'Private project']);
        Passport::actingAs($owner);
        $this->getJson(route('project.show', $project))->assertOk();
        $this->getJson(route('project.index'))->assertJsonCount(1, 'data');

        Passport::actingAs($other);

        $this->getJson(route('project.show', $project))->assertNotFound();
        $this->getJson(route('project.index'))->assertJsonCount(0, 'data');
        $this->getJson(route('project.index', ['status' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_cached_nested_reads_still_require_the_correct_parent(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'First project']);
        $otherProject = $user->projects()->create(['name' => 'Other project']);
        $board = app(BoardService::class)->createForProject($user, $project);
        Passport::actingAs($user);
        $this->getJson(route('project.boards.show', [$project, $board]))->assertOk();

        $this->getJson(route('project.boards.show', [$otherProject, $board]))->assertNotFound();
    }

    public function test_another_users_write_keeps_the_owners_cached_read(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $request = $this->readRequest($owner);
        $calls = 0;
        $resolve = function () use (&$calls): JsonResponse {
            return response()->json(['calls' => ++$calls]);
        };
        app(ApiReadCacheService::class)->response($request, $resolve);

        $other->projects()->create(['name' => 'Another users project']);

        $this->assertSame(['calls' => 1], app(ApiReadCacheService::class)->response($request, $resolve)->getData(true));
        $this->assertSame(1, $calls);
    }

    /** @return array<string, array{string}> */
    public static function changedModels(): array
    {
        $types = ['user', 'area', 'project', 'resource', 'tag', 'note', 'habit', 'journal', 'letter', 'calendar', 'board', 'stage', 'task', 'label', 'goal', 'check-in', 'export', 'letter media', 'note media', 'attachment', 'focus task', 'focus setting', 'focus session', 'notification'];

        return array_combine($types, array_map(fn (string $type): array => [$type], $types));
    }

    #[DataProvider('changedModels')]
    public function test_model_writes_invalidate_the_owners_reads(string $type): void
    {
        $user = User::factory()->create();
        $model = $this->ownedModel($user, $type);
        $request = $this->readRequest($user);
        $calls = 0;
        $resolve = function () use (&$calls): JsonResponse {
            return response()->json(['calls' => ++$calls]);
        };
        app(ApiReadCacheService::class)->response($request, $resolve);
        app(ApiReadCacheService::class)->response($request, $resolve);

        $model->forceFill(['updated_at' => now()->addMinute()])->save();

        $this->assertSame(['calls' => 2], app(ApiReadCacheService::class)->response($request, $resolve)->getData(true));
    }

    public function test_resource_pivot_changes_refresh_project_and_area_reads(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Project']);
        $area = $user->areas()->create(['name' => 'Area']);
        $resource = $user->resources()->create(['title' => 'Resource']);
        Passport::actingAs($user);
        $this->getJson(route('project.show', $project))->assertJsonCount(0, 'data.resources');
        $this->getJson(route('area.resources.index', $area))->assertJsonCount(0, 'data.data');
        $this->getJson(route('resource.show', $resource))->assertJsonCount(0, 'data.projects');

        $this->postJson(route('project.resources.store', $project), ['resource_uuids' => [$resource->uuid]])->assertOk();
        $this->postJson(route('area.resources.store', $area), ['resource_uuid' => $resource->uuid])->assertOk();

        $this->getJson(route('project.show', $project))->assertJsonPath('data.resources.0.uuid', $resource->uuid);
        $this->getJson(route('area.resources.index', $area))->assertJsonPath('data.data.0.uuid', $resource->uuid);
        $this->getJson(route('resource.show', $resource))->assertJsonPath('data.projects.0.uuid', $project->uuid);
        $this->deleteJson(route('project.resources.destroy', [$project, $resource]))->assertOk();
        $this->deleteJson(route('area.resources.destroy', [$area, $resource]))->assertOk();
        $this->getJson(route('project.show', $project))->assertJsonCount(0, 'data.resources');
        $this->getJson(route('area.resources.index', $area))->assertJsonCount(0, 'data.data');
    }

    public function test_task_pivot_only_edits_refresh_board_reads(): void
    {
        $user = User::factory()->create();
        $board = app(BoardService::class)->createStandalone($user);
        $task = app(BoardTaskService::class)->create($user, $board, ['title' => 'Task']);
        $label = $board->labels()->create(['name' => 'Label', 'color' => 'slate']);
        Passport::actingAs($user);
        $this->getJson(route('board.show', $board))->assertJsonCount(0, 'data.stages.0.tasks.0.labels');

        $this->putJson(route('board.tasks.update', [$board, $task]), ['label_uuids' => [$label->uuid]])->assertOk();

        $this->getJson(route('board.show', $board))->assertJsonPath('data.stages.0.tasks.0.labels.0.uuid', $label->uuid);
    }

    public function test_bulk_note_delete_and_restore_refresh_the_note_tree(): void
    {
        $user = User::factory()->create();
        $note = $user->standaloneNotes()->create(['title' => 'Parent', 'content' => '{}']);
        $child = $user->standaloneNotes()->create(['title' => 'Child', 'content' => '{}', 'parent_id' => $note->id]);
        Passport::actingAs($user);
        $this->getJson(route('notes.tree'))->assertJsonCount(1, 'data');

        $this->deleteJson(route('notes.destroy', $note))->assertOk();

        $this->getJson(route('notes.tree'))->assertJsonCount(0, 'data');
        $entry = $user->trashEntries()->firstOrFail();
        $this->postJson(route('trash.restore', $entry))->assertOk();
        $this->getJson(route('notes.tree'))->assertJsonPath('data.0.children.0.uuid', $child->uuid);
    }

    public function test_archiving_an_area_refreshes_bulk_reassigned_projects(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Area']);
        $project = $user->projects()->make(['name' => 'Project']);
        $project->area()->associate($area);
        $project->save();
        Passport::actingAs($user);
        $this->getJson(route('project.index'))->assertJsonPath('data.0.area.uuid', $area->uuid);
        $this->getJson(route('home.show'))->assertJsonPath('data.stats.areas', 1);

        $this->postJson(route('area.archive', $area))->assertOk();

        $this->getJson(route('project.index'))->assertJsonPath('data.0.area', null);
        $this->getJson(route('home.show'))->assertJsonPath('data.stats.areas', 0);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'area_id' => null]);
    }

    public function test_global_plan_edits_refresh_public_plans_and_authenticated_reads(): void
    {
        $plan = Plan::factory()->create(['name' => 'Sample before']);
        Passport::actingAs(User::factory()->create());
        $this->getJson(route('plan.index'))->assertJsonPath('data.0.name', 'Sample before');
        $this->getJson(route('project.index'))->assertOk();

        $plan->update(['name' => 'Sample after']);
        DB::enableQueryLog();

        $this->getJson(route('plan.index'))->assertJsonPath('data.0.name', 'Sample after');
        DB::flushQueryLog();
        $this->getJson(route('project.index'))->assertOk();
        $this->assertNotEmpty($this->domainQueries());
    }

    public function test_notification_reads_and_export_job_updates_refresh_cached_results(): void
    {
        $user = User::factory()->create();
        $notification = $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'calendar', 'data' => ['title' => 'Reminder']]);
        $letter = Letter::factory()->for($user)->create();
        $export = LetterExport::factory()->for($letter)->create();
        Passport::actingAs($user);
        $this->getJson(route('notifications.index', ['unread_only' => 1]))->assertJsonCount(1, 'data.data');
        $this->getJson(route('letters.exports.show', [$letter, $export]))->assertJsonPath('data.status', 'queued');

        $this->patchJson(route('notifications.read', $notification->id))->assertOk();
        $export->update(['status' => LetterExportStatus::READY]);

        $this->getJson(route('notifications.index', ['unread_only' => 1]))->assertJsonCount(0, 'data.data');
        $this->getJson(route('letters.exports.show', [$letter, $export]))->assertJsonPath('data.status', 'ready');
    }

    public function test_query_order_is_canonical_but_filters_urls_and_context_remain_distinct(): void
    {
        $user = User::factory()->create();
        $calls = 0;
        $resolve = function () use (&$calls): JsonResponse {
            return response()->json(['calls' => ++$calls]);
        };
        $cache = app(ApiReadCacheService::class);

        $cache->response($this->readRequest($user, 'http://localhost/read?status=active&page=1'), $resolve);
        $cache->response($this->readRequest($user, 'http://localhost/read?page=1&status=active'), $resolve);
        $this->assertSame(1, $calls);
        $cache->response($this->readRequest($user, 'http://localhost/read?status=archived&page=1'), $resolve);
        $cache->response($this->readRequest($user, 'http://localhost/read?status=active&page=2'), $resolve);
        $cache->response($this->readRequest($user, 'http://other-host/read?status=active&page=1'), $resolve);
        $cache->response($this->readRequest($user), $resolve, ['date' => '2026-10-05']);
        $cache->response($this->readRequest($user), $resolve, ['date' => '2026-10-06']);
        app()->setLocale('fr');
        $cache->response($this->readRequest($user), $resolve, ['date' => '2026-10-06']);

        $this->assertSame(7, $calls);
    }

    public function test_content_expires_and_disabled_caching_bypasses_existing_entries(): void
    {
        $this->freezeTime();
        $request = $this->readRequest(User::factory()->create());
        $calls = 0;
        $resolve = function () use (&$calls): JsonResponse {
            return response()->json(['calls' => ++$calls]);
        };
        $cache = app(ApiReadCacheService::class);
        $cache->response($request, $resolve);
        $this->travel(61)->seconds();
        $cache->response($request, $resolve);
        config(['cache.api_reads.enabled' => false]);
        $cache->response($request, $resolve);
        config(['cache.api_reads.enabled' => true, 'cache.api_reads.ttl' => 0]);
        $cache->response($request, $resolve);

        $this->assertSame(4, $calls);
    }

    public function test_public_plans_stay_cached_for_ten_minutes(): void
    {
        $this->freezeTime();
        Plan::factory()->create(['name' => 'Public plan']);
        $this->getJson(route('plan.index'))->assertJsonPath('data.0.name', 'Public plan');
        $this->travel(61)->seconds();
        DB::enableQueryLog();

        $this->getJson(route('plan.index'))->assertJsonPath('data.0.name', 'Public plan');

        $this->assertSame([], $this->domainQueries());
        DB::flushQueryLog();
        $this->travel(540)->seconds();
        $this->getJson(route('plan.index'))->assertJsonPath('data.0.name', 'Public plan');
        $this->assertNotEmpty($this->domainQueries());
    }

    public function test_archiving_deleting_and_restoring_projects_refreshes_cached_lists(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Project']);
        Passport::actingAs($user);
        $this->getJson(route('project.index'))->assertJsonCount(1, 'data');

        $this->postJson(route('project.archive', $project))->assertOk();

        $this->getJson(route('project.index'))->assertJsonCount(0, 'data');
        $this->getJson(route('project.index', ['status' => 'archived']))->assertJsonCount(1, 'data');
        $this->postJson(route('project.restore', $project))->assertOk();
        $this->getJson(route('project.index'))->assertJsonCount(1, 'data');
        $this->deleteJson(route('project.destroy', $project))->assertOk();
        $this->getJson(route('project.index'))->assertJsonCount(0, 'data');
        $this->postJson(route('trash.restore', $user->trashEntries()->firstOrFail()))->assertOk();
        $this->getJson(route('project.index'))->assertJsonCount(1, 'data');
    }

    public function test_a_day_change_refreshes_date_dependent_project_cards(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(23, 59, 50));
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'Due today', 'due_date' => '2026-10-05']);
        Passport::actingAs($user);
        $this->getJson(route('project.index'))->assertJsonPath('data.0.is_overdue', false);

        $this->travel(20)->seconds();

        $this->getJson(route('project.index'))->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_failed_responses_and_responses_with_cookies_are_not_cached(): void
    {
        $request = $this->readRequest(User::factory()->create());
        $cache = app(ApiReadCacheService::class);
        $cache->response($request, fn (): JsonResponse => response()->json(['error' => 'Failure'], 500));
        $cache->response($request, fn (): JsonResponse => response()->json(['result' => 'With cookie'])->withCookie(cookie('temporary', 'value')));

        $response = $cache->response($request, fn (): JsonResponse => response()->json(['result' => 'Fresh']));

        $this->assertSame(['result' => 'Fresh'], $response->getData(true));
    }

    public function test_reads_inside_transactions_bypass_the_cache_and_rollback_keeps_the_old_revision(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Committed']);
        $request = $this->readRequest($user);
        $cache = app(ApiReadCacheService::class);
        $resolve = fn (): JsonResponse => response()->json(['name' => $project->fresh()->name]);
        $cache->response($request, $resolve);
        DB::beginTransaction();
        try {
            $project->update(['name' => 'Uncommitted']);
            $this->assertSame(['name' => 'Uncommitted'], $cache->response($request, $resolve)->getData(true));
        } finally {
            DB::rollBack();
        }

        DB::enableQueryLog();
        $this->assertSame(['name' => 'Committed'], $cache->response($request, $resolve)->getData(true));
        $this->assertSame([], $this->domainQueries());
    }

    public function test_an_edit_during_a_cache_miss_cannot_populate_the_new_revision_with_old_data(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Before']);
        $request = $this->readRequest($user);
        $cache = app(ApiReadCacheService::class);
        $cache->response($request, function () use ($project): JsonResponse {
            $body = ['name' => $project->name];
            $project->update(['name' => 'After']);

            return response()->json($body);
        });

        $response = $cache->response($request, fn (): JsonResponse => response()->json(['name' => $project->fresh()->name]));

        $this->assertSame(['name' => 'After'], $response->getData(true));
    }

    public function test_a_failed_invalidation_rolls_back_the_database_edit(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Before']);
        Passport::actingAs($user);
        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'Before');
        $revisions = Mockery::mock(Repository::class);
        $revisions->shouldReceive('forever')->once()->andThrow(new RuntimeException('Cannot invalidate cached reads.'));
        Cache::extend('revision_failure', fn (): Repository => $revisions);
        config(['cache.stores.api_read_revisions.driver' => 'revision_failure']);
        Cache::forgetDriver('api_read_revisions');

        $this->putJson(route('project.update', $project), ['name' => 'After'])->assertInternalServerError();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Before']);
        config(['cache.stores.api_read_revisions.driver' => 'database']);
        Cache::forgetDriver('api_read_revisions');
        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'Before');
    }

    public function test_a_failed_invalidation_rolls_back_a_new_note(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $revisions = Mockery::mock(Repository::class);
        $revisions->shouldReceive('forever')->once()->andThrow(new RuntimeException('Cannot invalidate cached reads.'));
        Cache::extend('revision_failure', fn (): Repository => $revisions);
        config(['cache.stores.api_read_revisions.driver' => 'revision_failure']);
        Cache::forgetDriver('api_read_revisions');

        $this->postJson(route('notes.store'), ['title' => 'Note', 'content' => '{}'])->assertInternalServerError();

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_a_redis_outage_falls_back_and_recovery_cannot_revive_stale_results(): void
    {
        $available = true;
        $primary = new ArrayStore;
        $redis = Mockery::mock(ArrayStore::class)->makePartial();
        $redis->shouldReceive('get')->andReturnUsing(function (string $key) use (&$available, $primary): mixed {
            if (! $available) {
                throw new RuntimeException('Redis is unavailable');
            }

            return $primary->get($key);
        });
        $redis->shouldReceive('put')->andReturnUsing(function (string $key, mixed $value, int $seconds) use (&$available, $primary): bool {
            if (! $available) {
                throw new RuntimeException('Redis is unavailable');
            }

            return $primary->put($key, $value, $seconds);
        });
        Cache::extend('outage_test', fn (): Repository => new Repository($redis));
        config(['cache.stores.redis.driver' => 'outage_test', 'cache.api_reads.store' => 'api_reads']);
        Cache::forgetDriver(['redis', 'api_reads']);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Before']);
        Passport::actingAs($user);
        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'Before');
        Log::spy();

        $available = false;
        $this->putJson(route('project.update', $project), ['name' => 'After'])->assertOk();
        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'After');
        $available = true;

        $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'After');
        Log::shouldHaveReceived('warning')->once()->with(
            'Cache store unavailable; using the next configured store.',
            ['store' => 'redis', 'exception' => RuntimeException::class],
        );
    }

    public function test_cached_hits_still_count_towards_api_rate_limits(): void
    {
        Passport::actingAs(User::factory()->create());
        for ($request = 0; $request < 30; $request++) {
            $this->getJson(route('project.index'))->assertOk();
        }

        $this->getJson(route('project.index'))->assertTooManyRequests();
    }

    public function test_standalone_board_initialization_still_runs_after_a_cached_read(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $first = $this->getJson(route('board.index'))->assertOk();
        DB::table('boards')->where('user_id', $user->id)->update(['deleted_at' => now()]);

        $second = $this->getJson(route('board.index'))->assertOk();

        $this->assertNotSame($first->json('data.0.uuid'), $second->json('data.0.uuid'));
        $this->assertSame(1, $user->boards()->count());
    }

    public function test_focus_countdown_and_expiry_remain_live(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $user->focusSetting()->create([]);
        $session = $this->ownedModel($user, 'focus session');
        Passport::actingAs($user);
        $this->getJson(route('focus.show', ['timezone' => 'UTC']))->assertJsonPath('data.active_session.remaining_seconds', 60);

        $this->travel(10)->seconds();

        $this->getJson(route('focus.show', ['timezone' => 'UTC']))->assertJsonPath('data.active_session.remaining_seconds', 50);
        $this->travel(51)->seconds();
        $this->getJson(route('focus.show', ['timezone' => 'UTC']))->assertJsonPath('data.active_session', null);
        $this->assertDatabaseHas('focus_sessions', ['id' => $session->id, 'status' => 'completed']);
    }

    public function test_upcoming_calendar_reads_drop_events_that_have_started(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        CalendarPlan::factory()->for($user)->create(['starts_at' => now()->addSeconds(10), 'event_date' => now()->toDateString(), 'timezone' => 'UTC']);
        Passport::actingAs($user);
        $this->getJson(route('calendar.plans.upcoming', ['timezone' => 'UTC']))->assertJsonCount(1, 'data');

        $this->travel(20)->seconds();

        $this->getJson(route('calendar.plans.upcoming', ['timezone' => 'UTC']))->assertJsonCount(0, 'data');
    }

    public function test_trash_reads_drop_expired_items_immediately(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Deleted']);
        $entry = app(TrashService::class)->delete($user, $project, 'project', $project->name);
        $entry->update(['expires_at' => now()->addSeconds(10)]);
        Passport::actingAs($user);
        $this->getJson(route('trash.index'))->assertJsonCount(1, 'data.data');

        $this->travel(20)->seconds();

        $this->getJson(route('trash.index'))->assertJsonCount(0, 'data.data');
    }

    private function readRequest(User $user, string $url = 'http://localhost/read'): Request
    {
        $request = Request::create($url, 'GET');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }

    /** @return list<array{query: string, bindings: array, time: float}> */
    private function domainQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => (bool) preg_match(
            '/(?:from|join) "(?:projects|areas|resources|resource_tags|notes|letters|journal_entries|habits|board_tasks|focus_tasks|calendar_plans|notifications|plans)"/i',
            $query['query'],
        )));
    }

    private function ownedModel(User $user, string $type): Model
    {
        return match ($type) {
            'user' => $user,
            'area' => $user->areas()->create(['name' => 'Area']),
            'project' => $user->projects()->create(['name' => 'Project']),
            'resource' => $user->resources()->create(['title' => 'Resource']),
            'tag' => ResourceTag::query()->create(['user_id' => $user->id, 'name' => 'Tag', 'normalized_name' => 'tag']),
            'note' => $user->standaloneNotes()->create(['title' => 'Note', 'content' => '{}']),
            'habit' => $user->habits()->create(['name' => 'Habit']),
            'journal' => JournalEntry::factory()->for($user)->create(),
            'letter' => Letter::factory()->for($user)->create(),
            'calendar' => CalendarPlan::factory()->for($user)->create(),
            'board' => app(BoardService::class)->createStandalone($user),
            'stage' => app(BoardService::class)->createStandalone($user)->stages()->firstOrFail(),
            'task' => app(BoardTaskService::class)->create($user, app(BoardService::class)->createStandalone($user), ['title' => 'Task']),
            'label' => app(BoardService::class)->createStandalone($user)->labels()->create(['name' => 'Label', 'color' => 'slate']),
            'goal' => $user->areas()->create(['name' => 'Area'])->goals()->create(['title' => 'Goal']),
            'check-in' => $user->habits()->create(['name' => 'Habit'])->checkIns()->create(['check_in_date' => now()->toDateString(), 'completed' => true]),
            'export' => LetterExport::factory()->for(Letter::factory()->for($user))->create(),
            'letter media' => Letter::factory()->for($user)->create()->media()->create(['path' => 'letters/test.png', 'original_name' => 'test.png', 'mime_type' => 'image/png', 'size' => 10]),
            'note media' => $user->standaloneNotes()->create(['title' => 'Note', 'content' => '{}'])->media()->create(['path' => 'notes/test.png', 'original_name' => 'test.png', 'mime_type' => 'image/png', 'size' => 10]),
            'attachment' => $user->resources()->create(['title' => 'Resource'])->attachments()->create(['kind' => 'file', 'path' => 'resources/test.txt', 'original_name' => 'test.txt', 'mime_type' => 'text/plain', 'size' => 10]),
            'focus task' => $user->focusTasks()->create(['title' => 'Task']),
            'focus setting' => $user->focusSetting()->create([]),
            'focus session' => $user->focusSessions()->create(['type' => 'focus', 'status' => 'running', 'duration_seconds' => 60, 'remaining_seconds' => 60, 'started_at' => now(), 'ends_at' => now()->addMinute()]),
            'notification' => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'calendar', 'data' => ['title' => 'Reminder']]),
        };
    }
}
