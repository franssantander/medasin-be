<?php

namespace Tests\Feature\Search;

use App\Models\CalendarPlan;
use App\Models\JournalEntry;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_nine_groups_in_order_with_uuid_items(): void
    {
        $user = User::factory()->create();
        $created = $this->createAllModules($user, 'Orbit');
        Passport::actingAs($user);

        $response = $this->getJson(route('search.index', ['q' => ' orbit ']))->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('data.query', 'orbit');

        $groups = $response->json('data.groups');
        $this->assertSame(
            ['project', 'area', 'resource', 'note', 'journal', 'letter', 'plan', 'habit', 'goal'],
            array_column($groups, 'type'),
        );
        foreach ($groups as $group) {
            $this->assertSame(1, $group['total']);
            $this->assertSame($created[$group['type']], $group['items'][0]['id']);
            $this->assertSame($group['type'], $group['items'][0]['type']);
            $this->assertIsString($group['items'][0]['updated_at']);
        }
        $this->assertSame($created['area'], $groups[8]['items'][0]['area_uuid']);
        $this->assertArrayNotHasKey('area_uuid', $groups[0]['items'][0]);
    }

    public function test_never_returns_another_users_matches_in_any_group(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createAllModules($other, 'Private beacon');
        Passport::actingAs($owner);

        $this->getJson(route('search.index', ['q' => 'beacon']))->assertOk()
            ->assertJsonPath('data.groups', []);
    }

    public function test_archives_are_hidden_by_default_and_included_when_requested(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Vault area', 'archived_at' => now()]);
        $project = $user->projects()->create(['name' => 'Vault project', 'archived_at' => now()]);
        $resource = $user->resources()->create(['title' => 'Vault resource', 'archived_at' => now()]);
        $note = $area->notes()->create(['title' => 'Vault note', 'content' => 'Vault note body']);
        $goal = $area->goals()->create(['title' => 'Vault goal']);
        $habit = $user->habits()->create(['name' => 'Vault habit', 'area_id' => $area->id]);
        $plan = CalendarPlan::factory()->for($user)->create(['title' => 'Vault plan', 'area_id' => $area->id]);
        Passport::actingAs($user);

        $active = $this->getJson(route('search.index', ['q' => 'vault']))->assertOk()->json('data.groups');
        $this->assertSame(['plan', 'habit'], array_column($active, 'type'));
        $this->assertSame($plan->uuid, $active[0]['items'][0]['id']);
        $this->assertSame($habit->uuid, $active[1]['items'][0]['id']);

        $archived = $this->getJson(route('search.index', ['q' => 'vault', 'include_archived' => 'true']))->assertOk()->json('data.groups');
        $this->assertSame(
            ['project', 'area', 'resource', 'note', 'plan', 'habit', 'goal'],
            array_column($archived, 'type'),
        );
        foreach ($archived as $group) {
            $item = $group['items'][0];
            $this->assertSame(! in_array($group['type'], ['plan', 'habit'], true), $item['archived']);
        }
        $this->assertSame($project->uuid, $archived[0]['items'][0]['id']);
        $this->assertSame($resource->uuid, $archived[2]['items'][0]['id']);
        $this->assertSame($note->uuid, $archived[3]['items'][0]['id']);
        $this->assertSame($plan->uuid, $archived[4]['items'][0]['id']);
        $this->assertSame($goal->uuid, $archived[6]['items'][0]['id']);
    }

    public function test_soft_deleted_records_are_always_excluded(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Deleted beacon']);
        $models = [
            $user->projects()->create(['name' => 'Deleted beacon']),
            $area,
            $user->resources()->create(['title' => 'Deleted beacon']),
            $user->notes()->create(['title' => 'Deleted beacon', 'content' => 'body']),
            JournalEntry::factory()->for($user)->create(['title' => 'Deleted beacon']),
            Letter::factory()->for($user)->create(['title' => 'Deleted beacon']),
            CalendarPlan::factory()->for($user)->create(['title' => 'Deleted beacon']),
            $user->habits()->create(['name' => 'Deleted beacon']),
            $area->goals()->create(['title' => 'Deleted beacon']),
        ];
        foreach ($models as $model) {
            $model->delete();
        }
        Passport::actingAs($user);

        $this->getJson(route('search.index', ['q' => 'beacon', 'include_archived' => 'true']))
            ->assertOk()->assertJsonPath('data.groups', []);
    }

    public function test_type_limit_total_and_title_first_ranking(): void
    {
        $user = User::factory()->create();
        $body = $user->projects()->create(['name' => 'Older', 'description' => 'signal']);
        $title = $user->projects()->create(['name' => 'Signal title']);
        $user->projects()->create(['name' => 'Latest', 'description' => 'signal']);
        $body->forceFill(['updated_at' => now()->addDay()])->save();
        $title->forceFill(['updated_at' => now()->subDay()])->save();
        Passport::actingAs($user);

        $response = $this->getJson(route('search.index', ['q' => 'signal', 'type' => 'project', 'limit' => 1]))->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.total', 3)
            ->assertJsonCount(1, 'data.groups.0.items')
            ->assertJsonPath('data.groups.0.items.0.id', $title->uuid);
        $this->assertSame('name', $response->json('data.groups.0.items.0.match_field'));
    }

    public function test_literal_wildcards_do_not_match_unrelated_records(): void
    {
        $user = User::factory()->create();
        $percent = $user->projects()->create(['name' => '100% ready']);
        $underscore = $user->projects()->create(['name' => 'A_B draft']);
        $user->projects()->create(['name' => '1000 ready']);
        $user->projects()->create(['name' => 'AXB draft']);
        Passport::actingAs($user);

        $this->getJson(route('search.index', ['q' => '100%', 'type' => 'project']))->assertOk()
            ->assertJsonPath('data.groups.0.total', 1)
            ->assertJsonPath('data.groups.0.items.0.id', $percent->uuid);
        $this->getJson(route('search.index', ['q' => 'A_B', 'type' => 'project']))->assertOk()
            ->assertJsonPath('data.groups.0.total', 1)
            ->assertJsonPath('data.groups.0.items.0.id', $underscore->uuid);
    }

    public function test_another_users_attachment_does_not_match_my_unrelated_resource(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->resources()->create(['title' => 'Unrelated resource']);
        $foreign = $other->resources()->create(['title' => 'Foreign resource']);
        $foreign->attachments()->create(['kind' => 'file', 'original_name' => 'private beacon.pdf']);
        Passport::actingAs($owner);

        $this->getJson(route('search.index', ['q' => 'beacon', 'type' => 'resource']))
            ->assertOk()->assertJsonPath('data.groups', []);
    }

    public function test_snippet_keeps_a_long_matching_query_complete(): void
    {
        $user = User::factory()->create();
        $query = str_repeat('a', 100);
        $user->notes()->create(['title' => 'Draft', 'content' => str_repeat('x', 80).$query.str_repeat('z', 80)]);
        Passport::actingAs($user);

        $snippet = $this->getJson(route('search.index', ['q' => $query, 'type' => 'note']))
            ->assertOk()->json('data.groups.0.items.0.snippet');
        $this->assertStringContainsString($query, $snippet);
    }

    public function test_rich_text_body_and_resource_attachments_are_searchable_without_html_snippets(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Workspace']);
        $parent = $area->notes()->create(['title' => 'Draft folder', 'content' => '']);
        $note = $area->notes()->create([
            'parent_id' => $parent->id,
            'title' => 'Draft',
            'content' => json_encode(['version' => 1, 'blocks' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => '&lt;img src=x onerror=alert(1)&gt; star'],
                    ['type' => 'text', 'text' => 'light'],
                ],
            ]]], JSON_THROW_ON_ERROR),
        ]);
        $resource = $user->resources()->create(['title' => 'Reading']);
        $resource->attachments()->create(['kind' => 'file', 'original_name' => 'starlight.pdf']);
        Passport::actingAs($user);

        $response = $this->getJson(route('search.index', ['q' => 'starlight']))->assertOk();
        $groups = $response->json('data.groups');
        $this->assertSame(['resource', 'note'], array_column($groups, 'type'));
        $this->assertSame($resource->uuid, $groups[0]['items'][0]['id']);
        $this->assertSame('original_name', $groups[0]['items'][0]['match_field']);
        $this->assertSame($note->uuid, $groups[1]['items'][0]['id']);
        $this->assertStringContainsString('starlight', $groups[1]['items'][0]['snippet']);
        $this->assertStringNotContainsString('<img', $groups[1]['items'][0]['snippet']);
        $this->assertStringNotContainsString('&lt;img', $groups[1]['items'][0]['snippet']);
        $this->assertSame('starlight', substr($note->fresh()->content_text, -9));
    }

    public function test_journal_and_resource_writes_join_inline_text_without_spaces(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $journalContent = json_encode(['version' => 1, 'blocks' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'star'],
                ['type' => 'text', 'text' => 'light'],
            ],
        ]]], JSON_THROW_ON_ERROR);
        $journal = $this->postJson(route('journal.store'), ['title' => 'Reflection', 'content' => $journalContent])
            ->assertCreated()->json('data');
        $resourceContent = ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'star'],
                ['type' => 'text', 'text' => 'light'],
            ],
        ]]];
        $resource = $this->postJson(route('resource.store'), [
            'title' => 'Reading',
            'content' => json_encode($resourceContent, JSON_THROW_ON_ERROR),
        ])->assertCreated()->json('data');

        $groups = $this->getJson(route('search.index', ['q' => 'starlight']))->assertOk()->json('data.groups');
        $this->assertSame(['resource', 'journal'], array_column($groups, 'type'));
        $this->assertSame($resource['uuid'], $groups[0]['items'][0]['id']);
        $this->assertSame($journal['uuid'], $groups[1]['items'][0]['id']);
    }

    public function test_returns_422_with_standard_validation_envelope(): void
    {
        Passport::actingAs(User::factory()->create());
        foreach ([
            [['type' => 'note'], 'q'],
            [['q' => ' x '], 'q'],
            [['q' => str_repeat('x', 101)], 'q'],
            [['q' => 'valid', 'type' => 'unknown'], 'type'],
            [['q' => 'valid', 'limit' => 0], 'limit'],
            [['q' => 'valid', 'limit' => 11], 'limit'],
            [['q' => 'valid', 'include_archived' => 'maybe'], 'include_archived'],
        ] as [$params, $field]) {
            $this->getJson(route('search.index', $params))->assertUnprocessable()
                ->assertJsonPath('status', 422)
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_returns_401_without_authentication(): void
    {
        $this->getJson(route('search.index', ['q' => 'orbit']))->assertUnauthorized();
    }

    public function test_returns_429_after_search_rate_limit(): void
    {
        Passport::actingAs(User::factory()->create());
        for ($request = 0; $request < 60; $request++) {
            $this->getJson(route('search.index', ['q' => 'orbit', 'type' => 'project']))->assertOk();
        }

        $this->getJson(route('search.index', ['q' => 'orbit', 'type' => 'project']))
            ->assertStatus(429)->assertJsonPath('status', 429);
    }

    /** @return array<string, string> */
    private function createAllModules(User $user, string $term): array
    {
        $area = $user->areas()->create(['name' => $term.' area']);
        $project = $user->projects()->create(['name' => $term.' project']);
        $resource = $user->resources()->create(['title' => $term.' resource']);
        $note = $user->notes()->create(['title' => $term.' note', 'content' => 'Body']);
        $journal = JournalEntry::factory()->for($user)->create(['title' => $term.' journal']);
        $letter = Letter::factory()->for($user)->create(['title' => $term.' letter']);
        $plan = CalendarPlan::factory()->for($user)->create(['title' => $term.' plan']);
        $habit = $user->habits()->create(['name' => $term.' habit']);
        $goal = $area->goals()->create(['title' => $term.' goal']);

        return [
            'project' => $project->uuid,
            'area' => $area->uuid,
            'resource' => $resource->uuid,
            'note' => $note->uuid,
            'journal' => $journal->uuid,
            'letter' => $letter->uuid,
            'plan' => $plan->uuid,
            'habit' => $habit->uuid,
            'goal' => $goal->uuid,
        ];
    }
}
