<?php

namespace App\Services\Area;

use App\Models\Area;
use App\Models\Note;
use App\Models\NoteMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NoteService
{
    public function tree(Area $area): array
    {
        $notes = $area->notes()
            ->select(['id', 'uuid', 'parent_id', 'title', 'is_pinned', 'created_at', 'updated_at'])
            ->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->get();
        $uuidById = $notes->pluck('uuid', 'id');
        $childrenByParent = $notes->groupBy(fn (Note $note) => $note->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $childrenByParent, $uuidById): array {
            return $childrenByParent->get($parentId, collect())
                ->map(fn (Note $note) => [
                    'uuid' => $note->uuid,
                    'parent_uuid' => $note->parent_id ? $uuidById->get($note->parent_id) : null,
                    'title' => $note->title,
                    'is_pinned' => $note->is_pinned,
                    'created_at' => $note->created_at,
                    'updated_at' => $note->updated_at,
                    'children' => $build($note->getKey()),
                ])
                ->values()
                ->all();
        };

        return $build(0);
    }

    public function create(Area $area, array $attributes): Note
    {
        $parent = $this->resolveParent($area, Arr::pull($attributes, 'parent_uuid'));
        $attributes['parent_id'] = $parent?->getKey();

        return $area->notes()->create($attributes)->fresh();
    }

    public function update(Area $area, Note $note, array $attributes): Note
    {
        if (array_key_exists('parent_uuid', $attributes)) {
            $parent = $this->resolveParent($area, Arr::pull($attributes, 'parent_uuid'), $note);
            $attributes['parent_id'] = $parent?->getKey();
        }

        $note->update($attributes);

        return $note->fresh();
    }

    public function storeMedia(Area $area, Note $note, UploadedFile $file): array
    {
        $path = $file->store("areas/{$area->uuid}/notes/{$note->uuid}", 'public');
        $media = $note->media()->create([
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return [
            'uuid' => $media->uuid,
            'url' => url(Storage::disk('public')->url($media->path)),
            'kind' => str_starts_with($media->mime_type, 'image/') ? 'image' : 'video',
            'mime_type' => $media->mime_type,
            'name' => $media->original_name,
            'size' => $media->size,
        ];
    }

    public function deleteTree(Note $note): void
    {
        $ids = collect([$note->getKey()]);
        $frontier = $ids;

        while ($frontier->isNotEmpty()) {
            $frontier = Note::query()->whereIn('parent_id', $frontier)->pluck('id');
            $ids = $ids->merge($frontier);
        }

        $paths = NoteMedia::query()->whereIn('note_id', $ids)->pluck('path');

        DB::transaction(function () use ($ids): void {
            NoteMedia::query()->whereIn('note_id', $ids)->delete();
            Note::query()->whereIn('id', $ids)->delete();
        });

        Storage::disk('public')->delete($paths->all());
    }

    private function resolveParent(Area $area, ?string $parentUuid, ?Note $note = null): ?Note
    {
        if (! $parentUuid) {
            return null;
        }

        $parent = $area->notes()->where('uuid', $parentUuid)->first();

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'The selected parent note does not belong to this area.',
            ]);
        }

        $cursor = $parent;
        while ($note && $cursor) {
            if ($cursor->is($note)) {
                throw ValidationException::withMessages([
                    'parent_uuid' => 'A note cannot be moved into itself or one of its descendants.',
                ]);
            }

            $cursor = $cursor->parent_id ? Note::query()->find($cursor->parent_id) : null;
        }

        return $parent;
    }
}
