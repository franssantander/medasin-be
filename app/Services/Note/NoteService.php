<?php

namespace App\Services\Note;

use App\Data\Note\NoteData;
use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NoteService
{
    public function tree(HasMany $notes): array
    {
        $notes = $notes
            ->select(['id', 'uuid', 'parent_id', 'title', 'content', 'is_pinned', 'created_at', 'updated_at'])
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
                    'content' => $note->content,
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

    public function create(
        HasMany $notes,
        NoteData $data,
        string $parentErrorMessage = 'The selected parent note is not available in this note collection.',
    ): Note {
        $attributes = $data->toArray();
        $parent = $this->resolveParent($notes, Arr::pull($attributes, 'parent_uuid'), null, $parentErrorMessage);
        $attributes['parent_id'] = $parent?->getKey();

        return $notes->create($attributes)->fresh();
    }

    public function update(
        HasMany $notes,
        Note $note,
        NoteData $data,
        string $parentErrorMessage = 'The selected parent note is not available in this note collection.',
    ): Note {
        $attributes = $data->toArray();
        if (array_key_exists('parent_uuid', $attributes)) {
            $parent = $this->resolveParent($notes, Arr::pull($attributes, 'parent_uuid'), $note, $parentErrorMessage);
            $attributes['parent_id'] = $parent?->getKey();
        }

        $note->update($attributes);

        return $note->fresh();
    }

    public function storeMedia(Note $note, string $directory, UploadedFile $file): array
    {
        $path = $file->store($directory, 'public');
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

    private function resolveParent(
        HasMany $notes,
        ?string $parentUuid,
        ?Note $note = null,
        string $parentErrorMessage = 'The selected parent note is not available in this note collection.',
    ): ?Note {
        if (! $parentUuid) {
            return null;
        }

        $parent = (clone $notes)->where('uuid', $parentUuid)->first();

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_uuid' => $parentErrorMessage,
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
