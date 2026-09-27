<?php

namespace Tests\Feature\Resource;

use App\Models\User;
use App\Services\Dashboard\RecentResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecentResourceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_recent_individual_items_from_mixed_and_legacy_resources(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-08-31 12:00:00'));
        $user->resources()->create(['title' => 'Old file', 'type' => 'file']);

        $this->travelTo(Carbon::parse('2026-09-01 12:00:00'));
        $topic = $user->resources()->create([
            'title' => 'Guide',
            'type' => 'note',
            'url' => 'https://example.com/old-link',
            'content' => ['type' => 'doc', 'content' => []],
        ]);

        $this->travelTo(Carbon::parse('2026-09-02 12:00:00'));
        $link = $topic->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/new-link']);

        $this->travelTo(Carbon::parse('2026-09-03 12:00:00'));
        $file = $topic->attachments()->create(['kind' => 'file', 'original_name' => 'guide.pdf']);

        $this->travelTo(Carbon::parse('2026-09-04 12:00:00'));
        $image = $topic->attachments()->create(['kind' => 'image']);

        $this->travelTo(Carbon::parse('2026-09-05 12:00:00'));
        $legacyLink = $user->resources()->create([
            'title' => 'Old link',
            'type' => 'link',
            'url' => 'https://example.com/legacy',
        ]);

        $items = app(RecentResourceService::class)->forUser($user, 6);

        $this->assertSame([
            [
                'item_key' => 'legacy:'.$legacyLink->uuid,
                'type' => 'link',
                'title' => 'https://example.com/legacy',
                'resource_uuid' => $legacyLink->uuid,
                'occurred_at' => '2026-09-05T12:00:00.000000Z',
            ],
            [
                'item_key' => 'attachment:'.$image->uuid,
                'type' => 'image',
                'title' => 'Guide',
                'resource_uuid' => $topic->uuid,
                'occurred_at' => '2026-09-04T12:00:00.000000Z',
            ],
            [
                'item_key' => 'attachment:'.$file->uuid,
                'type' => 'file',
                'title' => 'guide.pdf',
                'resource_uuid' => $topic->uuid,
                'occurred_at' => '2026-09-03T12:00:00.000000Z',
            ],
            [
                'item_key' => 'attachment:'.$link->uuid,
                'type' => 'link',
                'title' => 'https://example.com/new-link',
                'resource_uuid' => $topic->uuid,
                'occurred_at' => '2026-09-02T12:00:00.000000Z',
            ],
            [
                'item_key' => 'legacy:'.$topic->uuid,
                'type' => 'link',
                'title' => 'https://example.com/old-link',
                'resource_uuid' => $topic->uuid,
                'occurred_at' => '2026-09-01T12:00:00.000000Z',
            ],
            [
                'item_key' => 'note:'.$topic->uuid,
                'type' => 'note',
                'title' => 'Guide',
                'resource_uuid' => $topic->uuid,
                'occurred_at' => '2026-09-01T12:00:00.000000Z',
            ],
        ], $items);
    }

    public function test_it_excludes_foreign_archived_deleted_and_trashed_items(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $visible = $user->resources()->create(['title' => 'Visible', 'type' => 'image']);
        $archived = $user->resources()->create(['title' => 'Archived', 'content' => ['type' => 'doc']]);
        $archived->forceFill(['archived_at' => now()])->save();
        $archived->attachments()->create(['kind' => 'link', 'url' => 'https://example.com/archived']);
        $deleted = $user->resources()->create(['title' => 'Deleted', 'content' => ['type' => 'doc']]);
        $deleted->attachments()->create(['kind' => 'file', 'original_name' => 'deleted.pdf']);
        $deleted->delete();
        $foreign = $otherUser->resources()->create(['title' => 'Foreign', 'content' => ['type' => 'doc']]);
        $foreign->attachments()->create(['kind' => 'image', 'original_name' => 'foreign.png']);
        $retired = $user->resources()->create(['title' => 'Retired', 'type' => 'file']);
        $retired->attachments()->create(['kind' => 'file', 'original_name' => 'retired.pdf'])->delete();

        $items = app(RecentResourceService::class)->forUser($user);

        $this->assertSame([[
            'item_key' => 'legacy:'.$visible->uuid,
            'type' => 'image',
            'title' => 'Visible',
            'resource_uuid' => $visible->uuid,
            'occurred_at' => $visible->updated_at->toJSON(),
        ]], $items);
    }

    public function test_items_with_equal_timestamps_use_a_stable_item_key_order(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-09-01 12:00:00'));
        $topic = $user->resources()->create(['title' => 'Guide', 'content' => ['type' => 'doc']]);
        $link = $topic->attachments()->create(['kind' => 'link', 'url' => 'https://example.com']);

        $items = app(RecentResourceService::class)->forUser($user);

        $this->assertSame([
            'attachment:'.$link->uuid,
            'note:'.$topic->uuid,
        ], array_column($items, 'item_key'));
    }
}
