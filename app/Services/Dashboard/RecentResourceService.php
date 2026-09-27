<?php

namespace App\Services\Dashboard;

use App\Models\Resource;
use App\Models\ResourceAttachment;
use App\Models\User;

class RecentResourceService
{
    /**
     * @return array<int, array{item_key: string, type: string, title: string, resource_uuid: string, occurred_at: string}>
     */
    public function forUser(User $user, int $limit = 5): array
    {
        $notes = $user->resources()
            ->whereNull('archived_at')
            ->whereNotNull('content')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'title', 'updated_at'])
            ->map(static fn (Resource $resource): array => [
                'item_key' => 'note:'.$resource->uuid,
                'type' => 'note',
                'title' => $resource->title,
                'resource_uuid' => $resource->uuid,
                'occurred_at' => $resource->updated_at->toJSON(),
            ])->all();

        $attachments = ResourceAttachment::query()
            ->whereHas('resource', fn ($query) => $query
                ->whereBelongsTo($user)
                ->whereNull('archived_at'))
            ->with('resource:id,uuid,title')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'resource_id', 'kind', 'url', 'original_name', 'updated_at'])
            ->map(static fn (ResourceAttachment $attachment): array => [
                'item_key' => 'attachment:'.$attachment->uuid,
                'type' => $attachment->kind,
                'title' => $attachment->kind === 'link'
                    ? ($attachment->url ?: $attachment->resource->title)
                    : ($attachment->original_name ?: $attachment->resource->title),
                'resource_uuid' => $attachment->resource->uuid,
                'occurred_at' => $attachment->updated_at->toJSON(),
            ])->all();

        $legacyLinks = $user->resources()
            ->whereNull('archived_at')
            ->whereNotNull('url')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'title', 'url', 'updated_at'])
            ->map(static fn (Resource $resource): array => [
                'item_key' => 'legacy:'.$resource->uuid,
                'type' => 'link',
                'title' => $resource->url ?: $resource->title,
                'resource_uuid' => $resource->uuid,
                'occurred_at' => $resource->updated_at->toJSON(),
            ])->all();

        $legacyTypes = $user->resources()
            ->whereNull('archived_at')
            ->whereNull('content')
            ->whereNull('url')
            ->whereDoesntHave('attachments', fn ($query) => $query->withTrashed())
            ->whereIn('type', ['note', 'link', 'file', 'image'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'title', 'type', 'updated_at'])
            ->map(static fn (Resource $resource): array => [
                'item_key' => 'legacy:'.$resource->uuid,
                'type' => $resource->type,
                'title' => $resource->title,
                'resource_uuid' => $resource->uuid,
                'occurred_at' => $resource->updated_at->toJSON(),
            ])->all();

        $items = array_merge($notes, $attachments, $legacyLinks, $legacyTypes);
        usort($items, static fn (array $left, array $right): int => strcmp($right['occurred_at'], $left['occurred_at'])
            ?: strcmp($left['item_key'], $right['item_key']));

        return array_slice($items, 0, $limit);
    }
}
