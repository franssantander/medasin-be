<?php

namespace Tests\Feature\Resource;

use App\Models\Resource;
use App\Models\ResourceAttachment;
use App\Models\ResourceTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_persists_mixed_content_tags_and_associations(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $areas = collect([
            $user->areas()->create(['name' => 'Work']),
            $user->areas()->create(['name' => 'Research']),
        ]);
        $projects = collect([
            $user->projects()->create(['name' => 'Launch']),
            $user->projects()->create(['name' => 'Website']),
        ]);
        $existing = ResourceTag::create(['user_id' => $user->id, 'name' => 'Work', 'normalized_name' => 'work']);
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Useful knowledge']]]]];

        $response = $this->actingAs($user, 'api')->post(route('resource.store'), [
            'title' => 'Reference',
            'icon' => 'LibraryBig',
            'background' => '#3B82F6',
            'content' => json_encode($content),
            'links' => ['https://example.com/guide'],
            'files' => [UploadedFile::fake()->createWithContent('guide.txt', 'Reference file'), UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='))],
            'tag_uuids' => [$existing->uuid],
            'tag_names' => [' WORK ', 'Research'],
            'project_uuids' => $projects->pluck('uuid')->all(),
            'area_uuids' => $areas->pluck('uuid')->all(),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.content', $content)
            ->assertJsonPath('data.icon', 'LibraryBig')
            ->assertJsonPath('data.background', '#3B82F6')
            ->assertJsonPath('data.types', ['file', 'image', 'link', 'note'])
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonCount(2, 'data.projects')
            ->assertJsonCount(2, 'data.areas');

        $resource = Resource::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertSame($user->id, $resource->user_id);
        $this->assertSame('LibraryBig', $resource->icon);
        $this->assertSame('#3B82F6', $resource->background);
        $this->assertSame('Useful knowledge', $resource->content_text);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('resource_tags', 2);
        $this->assertArrayNotHasKey('path', $response->json('data.attachments.1'));
        $this->getJson(route('resource.tags'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_title_only_creation_and_authentication(): void
    {
        $this->postJson(route('resource.store'), ['title' => 'Reference'])->assertUnauthorized();
        $this->getJson(route('resource.tags'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api')->postJson(route('resource.store'), ['title' => 'Reference'])
            ->assertCreated()->assertJsonPath('data.types', []);
    }

    public function test_update_replaces_and_clears_project_and_area_assignments(): void
    {
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Reference']);
        $oldProject = $user->projects()->create(['name' => 'Old project']);
        $newProjects = collect([
            $user->projects()->create(['name' => 'New project']),
            $user->projects()->create(['name' => 'Another project']),
        ]);
        $oldArea = $user->areas()->create(['name' => 'Old area']);
        $newAreas = collect([
            $user->areas()->create(['name' => 'New area']),
            $user->areas()->create(['name' => 'Another area']),
        ]);
        $resource->projects()->attach($oldProject);
        $resource->areas()->attach($oldArea);

        $this->actingAs($user, 'api')
            ->patchJson(route('resource.update', $resource->uuid), [
                'title' => 'Reference',
                'project_uuids' => $newProjects->pluck('uuid')->all(),
                'area_uuids' => $newAreas->pluck('uuid')->all(),
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.projects')
            ->assertJsonCount(2, 'data.areas');

        $this->assertEqualsCanonicalizing($newProjects->pluck('id')->all(), $resource->projects()->pluck('projects.id')->all());
        $this->assertEqualsCanonicalizing($newAreas->pluck('id')->all(), $resource->areas()->pluck('areas.id')->all());

        $this->patchJson(route('resource.update', $resource->uuid), [
            'title' => 'Reference',
            'project_uuids' => [],
            'area_uuids' => [],
        ])->assertOk()->assertJsonCount(0, 'data.projects')->assertJsonCount(0, 'data.areas');
    }

    public function test_owner_can_show_an_active_or_archived_resource(): void
    {
        $user = User::factory()->create();
        $active = $user->resources()->create(['title' => 'Active reference']);
        $archived = $user->resources()->create([
            'title' => 'Archived reference',
            'archived_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson(route('resource.show', $active->uuid))
            ->assertOk()
            ->assertJsonPath('data.uuid', $active->uuid)
            ->assertJsonPath('data.title', 'Active reference');

        $this->getJson(route('resource.show', $archived->uuid))
            ->assertOk()
            ->assertJsonPath('data.uuid', $archived->uuid)
            ->assertJsonPath('data.archived_at', $archived->archived_at->toJSON());
    }

    public function test_resource_show_requires_authentication_and_ownership(): void
    {
        $owner = User::factory()->create();
        $resource = $owner->resources()->create(['title' => 'Private']);

        $this->getJson(route('resource.show', $resource->uuid))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(route('resource.show', $resource->uuid))
            ->assertNotFound();
    }

    public function test_owner_can_archive_a_resource_idempotently(): void
    {
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Reference']);

        $this->actingAs($user, 'api')
            ->postJson(route('resource.archive', $resource->uuid))
            ->assertOk()
            ->assertJsonPath('message', 'Successfully archived resource.')
            ->assertJsonPath('data.uuid', $resource->uuid);

        $archivedAt = $resource->fresh()->archived_at;
        $this->assertNotNull($archivedAt);

        $this->postJson(route('resource.archive', $resource->uuid))->assertOk();
        $this->assertTrue($archivedAt->equalTo($resource->fresh()->archived_at));
        $this->getJson(route('resource.index'))->assertJsonPath('data.total', 0);
    }

    public function test_resource_archive_requires_authentication_and_ownership(): void
    {
        $owner = User::factory()->create();
        $resource = $owner->resources()->create(['title' => 'Private']);

        $this->postJson(route('resource.archive', $resource->uuid))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(route('resource.archive', $resource->uuid))
            ->assertNotFound();
        $this->assertNull($resource->fresh()->archived_at);
    }

    public function test_owner_can_restore_a_resource_idempotently(): void
    {
        $user = User::factory()->create();
        $resource = $user->resources()->create([
            'title' => 'Archived reference',
            'archived_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson(route('resource.restore', $resource->uuid))
            ->assertOk()
            ->assertJsonPath('message', 'Successfully restored resource.')
            ->assertJsonPath('data.uuid', $resource->uuid)
            ->assertJsonPath('data.archived_at', null);

        $this->assertNull($resource->fresh()->archived_at);
        $this->postJson(route('resource.restore', $resource->uuid))->assertOk();
        $this->getJson(route('resource.index'))->assertJsonPath('data.total', 1);
    }

    public function test_resource_restore_requires_authentication_and_ownership(): void
    {
        $owner = User::factory()->create();
        $resource = $owner->resources()->create([
            'title' => 'Private archive',
            'archived_at' => now(),
        ]);

        $this->postJson(route('resource.restore', $resource->uuid))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(route('resource.restore', $resource->uuid))
            ->assertNotFound();
        $this->assertNotNull($resource->fresh()->archived_at);
    }

    public function test_creation_rejects_invalid_input_and_foreign_associations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $area = $other->areas()->create(['name' => 'Private']);
        $project = $other->projects()->create(['name' => 'Private']);
        $tag = ResourceTag::create(['user_id' => $other->id, 'name' => 'Private', 'normalized_name' => 'private']);
        $this->actingAs($user, 'api')->postJson(route('resource.store'), [
            'title' => '', 'content' => 'invalid json', 'links' => ['javascript:alert(1)'],
            'icon' => str_repeat('a', 51), 'background' => 'blue',
            'area_uuids' => [$area->uuid], 'project_uuids' => [$project->uuid], 'tag_uuids' => [$tag->uuid],
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'icon', 'background', 'content', 'links.0', 'area_uuids.0', 'project_uuids.0', 'tag_uuids.0']);
        $area->forceFill(['user_id' => $user->id, 'archived_at' => now()])->save();
        $project->forceFill(['user_id' => $user->id, 'archived_at' => now()])->save();
        $this->postJson(route('resource.store'), ['title' => 'Test', 'area_uuids' => [$area->uuid], 'project_uuids' => [$project->uuid]])
            ->assertUnprocessable()->assertJsonValidationErrors(['area_uuids.0', 'project_uuids.0']);
        $this->assertDatabaseCount('resources', 0);
        $this->getJson(route('resource.tags'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('resource.index', ['tag_uuid' => $tag->uuid]))->assertUnprocessable()->assertJsonValidationErrors('tag_uuid');
    }

    public function test_upload_limits_and_transaction_cleanup(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(), 'api')->post(route('resource.store'), [
            'title' => 'Oversized', 'files' => [UploadedFile::fake()->create('large.pdf', 20481)],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files.0');

        ResourceAttachment::creating(function () {
            throw new \RuntimeException('Simulated attachment persistence failure');
        });
        try {
            $this->post(route('resource.store'), [
                'title' => 'Failed', 'files' => [UploadedFile::fake()->createWithContent('file.txt', 'content')],
                'tag_names' => ['Failed tag'],
            ], ['Accept' => 'application/json'])->assertServerError();
        } finally {
            ResourceAttachment::flushEventListeners();
            ResourceAttachment::clearBootedModels();
        }
        $this->assertDatabaseCount('resources', 0);
        $this->assertDatabaseCount('resource_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_private_downloads_require_owner_and_matching_resource(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Private']);
        Storage::disk('local')->put('resources/test.txt', 'secret');
        $attachment = $resource->attachments()->create(['kind' => 'file', 'path' => 'resources/test.txt', 'original_name' => 'test.txt', 'mime_type' => 'text/plain']);
        $url = route('resource.attachments.show', [$resource->uuid, $attachment->uuid]);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api')->getJson($url)->assertNotFound();
        $this->actingAs($user, 'api')->get($url)->assertOk()->assertDownload('test.txt');
        $another = $user->resources()->create(['title' => 'Other']);
        $this->getJson(route('resource.attachments.show', [$another->uuid, $attachment->uuid]))->assertNotFound();
    }

    public function test_pagination_and_combined_filters_preserve_matching_records(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('resource.store'), [
                'title' => "Reference {$i}", 'links' => ['https://example.com'],
                'content' => ['type' => 'doc', 'content' => [['text' => 'Needle']]], 'tag_names' => ['Research'],
            ])->assertCreated();
        }
        $tag = ResourceTag::first();
        $query = ['per_page' => 2, 'search' => 'NEEDLE', 'type' => 'link', 'tag_uuid' => $tag->uuid];
        $first = $this->getJson(route('resource.index', $query))->assertOk()
            ->assertJsonPath('data.total', 3)->assertJsonCount(2, 'data.data')->assertJsonPath('data.data.0.title', 'Reference 2');
        $this->getJson($first->json('data.next_page_url'))->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.next_page_url', null);
        $this->getJson(route('resource.index', [...$query, 'page' => 3]))->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson(route('resource.index', [...$query, 'type' => 'image']))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson(route('resource.index', ['per_page' => 101, 'page' => 0, 'type' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page', 'type']);
    }

    public function test_search_covers_legacy_fields_attachments_tags_and_literal_wildcards(): void
    {
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => '100% guide', 'description' => 'Legacy description', 'type' => 'file', 'url' => 'https://legacy.example.com']);
        $resource->attachments()->create(['kind' => 'image', 'original_name' => 'Diagram.png']);
        $tag = ResourceTag::create(['user_id' => $user->id, 'name' => 'Research', 'normalized_name' => 'research']);
        $resource->tags()->attach($tag);
        $deleted = $user->resources()->create(['title' => 'Deleted']);
        $deleted->delete();
        $this->actingAs($user, 'api');
        foreach (['100%', 'DESCRIPTION', 'legacy.example', 'diagram', 'research'] as $search) {
            $this->getJson(route('resource.index', ['search' => $search]))->assertOk()->assertJsonPath('data.total', 1);
        }
        foreach (['file', 'link', 'image'] as $type) {
            $this->getJson(route('resource.index', ['type' => $type]))->assertOk()->assertJsonPath('data.total', 1);
        }
        $this->getJson(route('resource.index'))->assertJsonPath('data.total', 1);
    }
}
