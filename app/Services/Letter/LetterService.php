<?php

namespace App\Services\Letter;

use App\Data\Letter\LetterData;
use App\Enum\LetterStatus;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LetterService
{
    public function __construct(
        private readonly LetterContentService $content,
    ) {}

    public function listing(User $user, ?LetterStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = $user->letters()
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
                'created_at',
                'updated_at',
            ])
            ->with([
                'user:id,uuid,first_name,last_name,username',
                'latestExport.letter',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->paginate($perPage);
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
            if ($updates !== []) {
                $updates['status'] = LetterStatus::DRAFT;
                $updates['exported_at'] = null;
                $letter->update($updates);
            }

            return $this->load($letter);
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
