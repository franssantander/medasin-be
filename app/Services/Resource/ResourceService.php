<?php

namespace App\Services\Resource;

use App\Data\Resource\ListResourceData;
use App\Data\Resource\ResourceData;
use App\Data\Resource\StoreResourceData;
use App\Enum\CoreFeature;
use App\Models\BoardTask;
use App\Models\Resource;
use App\Models\ResourceTag;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Plan\PlanQuotaService;
use App\Services\Profile\FileCleanupService;
use App\Services\Search\SearchText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ResourceService
{
    public function __construct(
        private readonly FileCleanupService $fileCleanup,
        private readonly PlanQuotaService $quota,
    ) {}

    public function tags(User $user): array
    {
        return ResourceTag::query()->where('user_id', $user->id)->orderBy('normalized_name')->get(['uuid', 'name'])->toArray();
    }

    public function listing(User $user, ListResourceData $data): array
    {
        $filters = $data->toArray();
        $query = $user->resources()
            ->when(
                $data->status === 'archived',
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->with(['attachments', 'tags', 'projects', 'areas']);

        if ($type = $filters['type'] ?? null) {
            $query->where(function ($query) use ($type) {
                $query->where('type', $type);
                if ($type === 'note') {
                    $query->orWhereNotNull('content');
                } elseif ($type === 'link') {
                    $query->orWhereNotNull('url');
                }
                $query->orWhereHas('attachments', fn ($attachments) => $attachments->where('kind', $type));
            });
        }

        if ($tag = $filters['tag_uuid'] ?? null) {
            $query->whereHas('tags', fn ($tags) => $tags->where('uuid', $tag));
        }

        if ($search = $filters['search'] ?? null) {
            // Escape LIKE wildcards so search remains a literal substring match.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($query) use ($pattern) {
                foreach (['title', 'description', 'content_text', 'url'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                $query->orWhereHas('attachments', fn ($attachments) => $attachments
                    ->whereRaw("LOWER(url) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(original_name) LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereHas('tags', fn ($tags) => $tags->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }

        return $query
            ->when($data->status === 'archived', fn ($query) => $query->orderByDesc('resources.archived_at'))
            ->orderByDesc('resources.created_at')->orderByDesc('resources.id')
            ->paginate($data->per_page, ['*'], 'page', $data->page)->withQueryString()
            ->through(fn (Resource $resource) => $this->serialize($resource))->toArray();
    }

    public function create(User $user, StoreResourceData $data): array
    {
        return $this->quota->transaction($user, function (User $user) use ($data): array {
            $this->quota->assertCanIncrease($user, CoreFeature::RESOURCES);
            $this->quota->assertCanStore($user, $this->totalBytes($data->files));
            $resource = $user->resources()->create([
                'title' => $data->title,
                'icon' => $data->icon,
                'background' => $data->background,
                'content' => $data->content,
                'content_text' => SearchText::fromDocument($data->content),
            ]);

            foreach ($data->links as $url) {
                $resource->attachments()->create(['kind' => 'link', 'url' => $url]);
            }

            foreach ($data->files as $file) {
                $directory = "resources/{$resource->uuid}";
                $filename = $file->hashName();
                $path = "{$directory}/{$filename}";
                DB::afterRollBack(function () use ($path): void {
                    try {
                        $this->fileCleanup->deleteOrQueue(['local' => [$path]]);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
                if ($file->storeAs($directory, $filename, 'local') === false) {
                    throw new RuntimeException('Unable to store resource attachment.');
                }
                $mime = $file->getMimeType() ?: 'application/octet-stream';
                $resource->attachments()->create([
                    'kind' => in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? 'image' : 'file',
                    'path' => $path,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'mime_type' => $mime,
                    'size' => $file->getSize(),
                ]);
            }

            $tagIds = ResourceTag::query()->where('user_id', $user->id)->whereIn('uuid', $data->tag_uuids)->pluck('id')->all();
            foreach ($data->tag_names as $name) {
                $name = trim($name);
                $tagIds[] = ResourceTag::firstOrCreate([
                    'user_id' => $user->id,
                    'normalized_name' => mb_strtolower($name),
                ], ['name' => $name])->id;
            }
            $resource->tags()->sync(array_unique($tagIds));

            $projectIds = $user->projects()->whereIn('uuid', $data->project_uuids)->pluck('id')->all();
            $areaIds = $user->areas()->whereIn('uuid', $data->area_uuids)->pluck('id')->all();
            $resource->projects()->sync($projectIds);
            $resource->areas()->sync($areaIds);

            app(ApiReadCacheService::class)->invalidateUser($user);

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    /** Partial update: fields and links missing from the request are left as they are. */
    public function update(User $user, Resource $resource, array $data): array
    {
        return DB::transaction(function () use ($user, $resource, $data) {
            $attributes = Arr::only($data, ['title', 'icon', 'background', 'content']);
            if (array_key_exists('content', $data)) {
                $attributes['content_text'] = SearchText::fromDocument($data['content']);
            }
            if ($attributes !== []) {
                $resource->update($attributes);
            }

            if (array_key_exists('tag_uuids', $data) || array_key_exists('tag_names', $data)) {
                $tagIds = ResourceTag::query()->where('user_id', $user->id)->whereIn('uuid', $data['tag_uuids'] ?? [])->pluck('id')->all();
                foreach ($data['tag_names'] ?? [] as $name) {
                    $name = trim($name);
                    $tagIds[] = ResourceTag::firstOrCreate([
                        'user_id' => $user->id,
                        'normalized_name' => mb_strtolower($name),
                    ], ['name' => $name])->id;
                }
                $resource->tags()->sync(array_unique($tagIds));
            }

            // Only active Projects and Areas can be chosen, so links to archived ones are kept rather than dropped.
            if (array_key_exists('project_uuids', $data)) {
                $resource->projects()->sync([
                    ...$user->projects()->whereIn('uuid', $data['project_uuids'])->pluck('id')->all(),
                    ...$resource->projects()->whereNotNull('archived_at')->pluck('projects.id')->all(),
                ]);
            }
            if (array_key_exists('area_uuids', $data)) {
                $resource->areas()->sync([
                    ...$user->areas()->whereIn('uuid', $data['area_uuids'])->pluck('id')->all(),
                    ...$resource->areas()->whereNotNull('archived_at')->pluck('areas.id')->all(),
                ]);
            }

            app(ApiReadCacheService::class)->invalidateUser($user);

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    public function archive(Resource $resource): array
    {
        return DB::transaction(function () use ($resource) {
            $resource = Resource::query()->whereKey($resource->getKey())->lockForUpdate()->firstOrFail();

            // Archived resources leave every context, but the links are remembered so unarchiving can restore them.
            $previous = $resource->archived_links ?? [];
            $merge = fn (string $key, array $ids): array => array_values(array_unique([...($previous[$key] ?? []), ...$ids]));
            $links = [
                'area_ids' => $merge('area_ids', $resource->areas()->pluck('areas.id')->all()),
                'project_ids' => $merge('project_ids', $resource->projects()->pluck('projects.id')->all()),
                'board_task_ids' => $merge('board_task_ids', $resource->boardTasks()->pluck('board_tasks.id')->all()),
            ];

            $resource->areas()->detach();
            $resource->projects()->detach();
            $resource->boardTasks()->detach();
            $resource->forceFill([
                'archived_at' => $resource->archived_at ?? now(),
                'archived_links' => $links,
            ])->save();
            app(ApiReadCacheService::class)->invalidateScope('user:'.$resource->user()->value('uuid'));

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    /** Unarchives the resource and re-links the contexts it had, skipping any that were deleted since. */
    public function unarchive(Resource $resource): array
    {
        return DB::transaction(function () use ($resource) {
            $resource = Resource::query()->whereKey($resource->getKey())->lockForUpdate()->firstOrFail();
            $user = $resource->user()->firstOrFail();
            $links = $resource->archived_links ?? [];

            $resource->areas()->syncWithoutDetaching(
                $user->areas()->whereIn('id', $links['area_ids'] ?? [])->pluck('id')->all(),
            );
            $resource->projects()->syncWithoutDetaching(
                $user->projects()->whereIn('id', $links['project_ids'] ?? [])->pluck('id')->all(),
            );
            $resource->boardTasks()->syncWithoutDetaching(
                BoardTask::query()
                    ->whereIn('id', $links['board_task_ids'] ?? [])
                    ->whereHas('board', fn ($query) => $query->where('user_id', $user->getKey()))
                    ->pluck('id')
                    ->all(),
            );

            $resource->forceFill(['archived_at' => null, 'archived_links' => null])->save();
            app(ApiReadCacheService::class)->invalidateScope('user:'.$user->uuid);

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    public function addAttachments(Resource $resource, array $links, array $files): array
    {
        return DB::transaction(function () use ($resource, $links, $files): array {
            $user = User::query()->lockForUpdate()->findOrFail($resource->user_id);
            $resource = $user->resources()->whereKey($resource->getKey())->firstOrFail();
            $this->quota->assertCanStore($user, $this->totalBytes($files));

            foreach ($links as $url) {
                $resource->attachments()->create(['kind' => 'link', 'url' => $url]);
            }
            foreach ($files as $file) {
                $directory = "resources/{$resource->uuid}";
                $filename = $file->hashName();
                $path = "{$directory}/{$filename}";
                DB::afterRollBack(function () use ($path): void {
                    try {
                        $this->fileCleanup->deleteOrQueue(['local' => [$path]]);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
                if ($file->storeAs($directory, $filename, 'local') === false) {
                    throw new RuntimeException('Unable to store resource attachment.');
                }
                $mime = $file->getMimeType() ?: 'application/octet-stream';
                $resource->attachments()->create([
                    'kind' => in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? 'image' : 'file',
                    'path' => $path,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'mime_type' => $mime,
                    'size' => $file->getSize(),
                ]);
            }

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    public function serialize(Resource $resource): array
    {
        $types = $resource->attachments->pluck('kind');
        if ($resource->content !== null) {
            $types->push('note');
        }
        if ($resource->url !== null) {
            $types->push('link');
        }
        if (in_array($resource->type, ['note', 'link', 'image', 'file'], true)) {
            $types->push($resource->type);
        }

        return [
            ...ResourceData::fromModel($resource)->toArray(),
            'content' => $resource->content,
            'types' => $types->unique()->sort()->values()->all(),
            'attachments' => $resource->attachments->map(fn ($attachment) => [
                'uuid' => $attachment->uuid,
                'kind' => $attachment->kind,
                'url' => $attachment->kind === 'link' ? $attachment->url : route('resource.attachments.show', [$resource->uuid, $attachment->uuid]),
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ])->all(),
            'tags' => $resource->tags->map->only(['uuid', 'name'])->all(),
            'projects' => $resource->projects->map->only(['uuid', 'name'])->all(),
            'areas' => $resource->areas->map->only(['uuid', 'name'])->all(),
        ];
    }

    /** @param  array<int, UploadedFile>  $files */
    private function totalBytes(array $files): int
    {
        return array_sum(array_map(fn (UploadedFile $file): int => (int) $file->getSize(), $files));
    }
}
