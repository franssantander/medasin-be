<?php

namespace App\Services\Letter;

use App\Data\Letter\LetterData;
use App\Enum\LetterListFilter;
use App\Enum\LetterStatus;
use App\Models\Letter;
use App\Models\LetterMedia;
use App\Models\User;
use App\Services\Plan\PlanQuotaService;
use App\Services\Profile\FileCleanupService;
use App\Support\ImageMetadataStripper;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LetterService
{
    public function __construct(
        private readonly LetterContentService $content,
        private readonly FileCleanupService $fileCleanup,
        private readonly PlanQuotaService $quota,
        private readonly ImageMetadataStripper $metadata,
    ) {}

    public function listing(
        User $user,
        ?LetterListFilter $filter = null,
        ?string $search = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = $this->searchQuery($user, $search)
            ->select([
                'id',
                'uuid',
                'user_id',
                'title',
                'subtitle',
                'content_text',
                'word_count',
                'read_time_minutes',
                'status',
                'exported_at',
                'done_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'user:id,uuid,first_name,last_name,username',
                'latestExport.letter',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        match ($filter) {
            LetterListFilter::DRAFT => $query->whereNull('done_at'),
            LetterListFilter::DONE => $query->whereNotNull('done_at'),
            null => null,
        };

        return $query->paginate($perPage);
    }

    /**
     * Tab totals for the library, narrowed by the search but not by the tab.
     *
     * @return array{all: int, draft: int, done: int}
     */
    public function counts(User $user, ?string $search = null): array
    {
        $totals = $this->searchQuery($user, $search)
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(done_at) AS done')
            ->first();
        $all = (int) ($totals->total ?? 0);
        $done = (int) ($totals->done ?? 0);

        return [
            'all' => $all,
            'draft' => $all - $done,
            'done' => $done,
        ];
    }

    public function find(User $user, Letter $letter): Letter
    {
        return $user->letters()
            ->whereKey($letter->getKey())
            ->with([
                'user:id,uuid,first_name,last_name,username',
                'latestExport.letter',
            ])
            ->firstOrFail();
    }

    public function create(User $user, LetterData $data): Letter
    {
        $attributes = $data->toArray();
        $content = (string) $attributes['content'];
        $contentText = $this->content->text($content);
        $wordCount = $this->content->wordCount($contentText);

        return DB::transaction(function () use ($user, $attributes, $content, $contentText, $wordCount): Letter {
            $letter = $user->letters()->create([
                'title' => $attributes['title'],
                'subtitle' => $attributes['subtitle'] ?? null,
                'content' => $content,
                'content_text' => $contentText !== '' ? $contentText : null,
                'word_count' => $wordCount,
                'read_time_minutes' => $this->content->readTimeMinutes($wordCount),
                'status' => LetterStatus::DRAFT,
                'exported_at' => null,
            ]);

            return $this->load($letter);
        });
    }

    public function update(User $user, Letter $letter, LetterData $data): Letter
    {
        $attributes = $data->toArray();

        return DB::transaction(function () use ($letter, $attributes): Letter {
            $updates = [];
            if (array_key_exists('title', $attributes)) {
                $updates['title'] = $attributes['title'];
            }
            if (array_key_exists('subtitle', $attributes)) {
                $updates['subtitle'] = $attributes['subtitle'];
            }
            if (array_key_exists('content', $attributes)) {
                $content = (string) $attributes['content'];
                $contentText = $this->content->text($content);
                $wordCount = $this->content->wordCount($contentText);
                $updates['content'] = $content;
                $updates['content_text'] = $contentText !== '' ? $contentText : null;
                $updates['word_count'] = $wordCount;
                $updates['read_time_minutes'] = $this->content->readTimeMinutes($wordCount);
            }
            // Exported pages describe the text, so only text edits reset them.
            if ($updates !== []) {
                $updates['status'] = LetterStatus::DRAFT;
                $updates['exported_at'] = null;
            }
            if (array_key_exists('done', $attributes)) {
                $updates['done_at'] = $attributes['done'] ? ($letter->done_at ?? now()) : null;
            }
            if ($updates !== []) {
                $letter->update($updates);
            }

            return $this->load($letter);
        });
    }

    public function storeMedia(Letter $letter, UploadedFile $file): array
    {
        return DB::transaction(function () use ($letter, $file): array {
            $user = User::query()->lockForUpdate()->findOrFail($letter->user_id);
            $letter = $user->letters()->whereKey($letter->getKey())->firstOrFail();
            $this->metadata->strip($file->getRealPath());
            $this->quota->assertCanStore($user, (int) $file->getSize());
            $directory = "letters/{$user->uuid}/{$letter->uuid}";
            $filename = $file->hashName();
            $path = "{$directory}/{$filename}";
            DB::afterRollBack(function () use ($path): void {
                try {
                    $this->fileCleanup->deleteOrQueue([LetterMedia::DISK => [$path]]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
            if ($file->storeAs($directory, $filename, LetterMedia::DISK) === false) {
                throw new RuntimeException('Unable to store letter image.');
            }
            $media = $letter->media()->create([
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);

            return [
                'uuid' => $media->uuid,
                'url' => $media->url(),
                'kind' => 'image',
                'mime_type' => $media->mime_type,
                'name' => $media->original_name,
                'size' => $media->size,
            ];
        });
    }

    /**
     * @return HasMany<Letter, User>
     */
    private function searchQuery(User $user, ?string $search): HasMany
    {
        $query = $user->letters();
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        // Escape LIKE wildcards so search remains a literal substring match.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';

        return $query->where(function ($query) use ($pattern): void {
            foreach (['title', 'subtitle', 'content_text'] as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    private function load(Letter $letter): Letter
    {
        return $letter->fresh([
            'user:id,uuid,first_name,last_name,username',
            'latestExport.letter',
        ]);
    }
}
