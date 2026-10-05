<?php

namespace App\Services\Resource;

use App\Data\Resource\ListResourceData;
use App\Data\Resource\ResourceData;
use App\Data\Resource\StoreResourceData;
use App\Models\Resource;
use App\Models\ResourceTag;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Profile\FileCleanupService;
use App\Services\Search\SearchText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ResourceService
{
    public function __construct(
        private readonly FileCleanupService $fileCleanup,
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
        return DB::transaction(function () use ($user, $data): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
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

    public function update(User $user, Resource $resource, array $data): array
    {
        return DB::transaction(function () use ($user, $resource, $data) {
            $content = $data['content'] ?? null;
            $resource->update([
                'title' => $data['title'],
                'icon' => $data['icon'] ?? null,
                'background' => $data['background'] ?? null,
                'content' => $content,
                'content_text' => SearchText::fromDocument($content),
            ]);

            $tagIds = ResourceTag::query()->where('user_id', $user->id)->whereIn('uuid', $data['tag_uuids'] ?? [])->pluck('id')->all();
            foreach ($data['tag_names'] ?? [] as $name) {
                $name = trim($name);
                $tagIds[] = ResourceTag::firstOrCreate([
                    'user_id' => $user->id,
                    'normalized_name' => mb_strtolower($name),
                ], ['name' => $name])->id;
            }
            $resource->tags()->sync(array_unique($tagIds));

            $projectIds = $user->projects()->whereIn('uuid', $data['project_uuids'] ?? [])->pluck('id')->all();
            $areaIds = $user->areas()->whereIn('uuid', $data['area_uuids'] ?? [])->pluck('id')->all();
            $resource->projects()->sync($projectIds);
            $resource->areas()->sync($areaIds);

            app(ApiReadCacheService::class)->invalidateUser($user);

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    public function archive(Resource $resource): array
    {
        return DB::transaction(function () use ($resource) {
            $resource->areas()->detach();
            $resource->projects()->detach();
            $resource->boardTasks()->detach();
            app(ApiReadCacheService::class)->invalidateScope('user:'.$resource->user()->value('uuid'));

            if ($resource->archived_at === null) {
                $resource->forceFill(['archived_at' => now()])->save();
            }

            return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
        });
    }

    public function addAttachments(Resource $resource, array $links, array $files): array
    {
        return DB::transaction(function () use ($resource, $links, $files): array {
            $user = User::query()->lockForUpdate()->findOrFail($resource->user_id);
            $resource = $user->resources()->whereKey($resource->getKey())->firstOrFail();

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

    public function deleteAttachment(Resource $resource, string $uuid): array
    {
        $attachment = $resource->attachments()->where('uuid', $uuid)->firstOrFail();
        if ($attachment->path) {
            Storage::disk('local')->delete($attachment->path);
        }
        $attachment->deleteOrFail();

        return $this->serialize($resource->fresh(['attachments', 'tags', 'projects', 'areas']));
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
}
