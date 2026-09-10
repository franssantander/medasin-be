<?php

namespace App\Services\Journal;

use App\Data\Journal\JournalEntryData;
use App\Models\FocusSession;
use App\Models\JournalEntry;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JournalService
{
    public function listing(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->journalEntries()
            ->select([
                'id',
                'uuid',
                'user_id',
                'focus_session_id',
                'title',
                'content_text',
                'created_at',
                'updated_at',
            ])
            ->with($this->relations())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(User $user, JournalEntry $entry): JournalEntry
    {
        return $user->journalEntries()
            ->whereKey($entry->getKey())
            ->with($this->relations())
            ->firstOrFail();
    }

    public function create(User $user, JournalEntryData $data): JournalEntry
    {
        $attributes = $data->toArray();
        $resourceUuids = Arr::pull($attributes, 'resource_uuids', []);

        return DB::transaction(function () use ($user, $attributes, $resourceUuids): JournalEntry {
            $entry = $user->journalEntries()->create([
                'title' => $attributes['title'],
                'content' => $attributes['content'],
                'content_text' => $this->contentText($attributes['content']),
            ]);
            $this->syncResources($user, $entry, $resourceUuids);

            return $this->load($entry);
        });
    }

    public function update(User $user, JournalEntry $entry, JournalEntryData $data): JournalEntry
    {
        $attributes = $data->toArray();
        $resourceUuids = null;
        if (array_key_exists('resource_uuids', $attributes)) {
            $resourceUuids = Arr::pull($attributes, 'resource_uuids');
        }

        return DB::transaction(function () use ($user, $entry, $attributes, $resourceUuids): JournalEntry {
            $updates = [];
            if (array_key_exists('title', $attributes)) {
                $updates['title'] = $attributes['title'];
            }
            if (array_key_exists('content', $attributes)) {
                $updates['content'] = $attributes['content'];
                $updates['content_text'] = $this->contentText($attributes['content']);
            }
            if ($updates !== []) {
                $entry->update($updates);
            }
            if ($resourceUuids !== null) {
                $this->syncResources($user, $entry, $resourceUuids);
            }

            return $this->load($entry);
        });
    }

    public function upsertFocusReflection(
        User $user,
        FocusSession $session,
        ?string $mood,
        ?string $note,
    ): JournalEntry {
        if ($session->user_id !== $user->getKey()) {
            abort(404);
        }

        $entry = $user->journalEntries()
            ->withTrashed()
            ->where('focus_session_id', $session->getKey())
            ->first();
        $attributes = [
            'title' => $this->focusReflectionTitle($session),
            'content' => $this->documentFromNote($note),
            'content_text' => $note !== null ? ($this->contentText($this->documentFromNote($note)) ?: null) : null,
            'focus_session_id' => $session->getKey(),
        ];

        if ($entry) {
            if ($entry->trashed()) {
                $entry->restore();
                TrashEntry::query()
                    ->where('subject_type', JournalEntry::class)
                    ->where('subject_id', $entry->getKey())
                    ->delete();
            }
            $entry->forceFill($attributes)->save();
        } else {
            $entry = $user->journalEntries()->create($attributes);
        }

        return $this->load($entry);
    }

    private function syncResources(User $user, JournalEntry $entry, array $resourceUuids): void
    {
        $resourceUuids = array_values(array_unique($resourceUuids));
        $resourceIds = $user->resources()
            ->whereNull('archived_at')
            ->whereIn('uuid', $resourceUuids)
            ->pluck('resources.id')
            ->all();

        if (count($resourceIds) !== count($resourceUuids)) {
            throw ValidationException::withMessages([
                'resource_uuids' => 'All selected resources must be active resources owned by you.',
            ]);
        }

        $entry->resources()->sync($resourceIds);
    }

    private function load(JournalEntry $entry): JournalEntry
    {
        return $entry->fresh($this->relations());
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'resources:id,uuid,title,type,archived_at',
            'focusSession:id,uuid,task_title,completed_at,mood',
        ];
    }

    private function focusReflectionTitle(FocusSession $session): string
    {
        $subject = trim((string) $session->task_title);
        if ($subject === '') {
            $subject = $session->completed_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        }

        return Str::limit('Focus reflection — '.$subject, 120, '');
    }

    private function documentFromNote(?string $note): string
    {
        return json_encode([
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'content' => $note ?? '',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function contentText(string $content): ?string
    {
        $document = json_decode($content, true);
        if (! is_array($document)) {
            return trim($content) ?: null;
        }

        $text = trim($this->extractText($document));

        return $text !== '' ? $text : null;
    }

    private function extractText(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : '';
        }

        $parts = [];
        if (isset($value['text']) && is_string($value['text'])) {
            $parts[] = $value['text'];
        }
        foreach (['blocks', 'content', 'children', 'rows', 'cells'] as $key) {
            if (array_key_exists($key, $value)) {
                $parts[] = $this->extractText($value[$key]);
            }
        }
        foreach ($value as $key => $child) {
            if (is_int($key)) {
                $parts[] = $this->extractText($child);
            }
        }
        if (isset($value['props']['label']) && is_string($value['props']['label'])) {
            $parts[] = $value['props']['label'];
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
