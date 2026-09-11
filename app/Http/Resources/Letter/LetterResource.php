<?php

namespace App\Http\Resources\Letter;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class LetterResource extends JsonResource
{
    private bool $includeContent = false;

    public function withContent(bool $includeContent = true): static
    {
        $this->includeContent = $includeContent;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $this->relationLoaded('user') ? $this->user : null;
        $data = [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'content_preview' => Str::limit((string) $this->content_text, 240, '...'),
            'word_count' => $this->word_count,
            'read_time_minutes' => $this->read_time_minutes,
            'status' => $this->status?->value,
            'exported_at' => $this->exported_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'author' => $user ? [
                'name' => trim($user->first_name.' '.$user->last_name),
                'handle' => '@'.$user->username,
            ] : null,
            'latest_export' => $this->relationLoaded('latestExport') && $this->latestExport
                ? LetterExportResource::make($this->latestExport)->resolve($request)
                : null,
        ];

        if ($this->includeContent) {
            $data['content'] = $this->content;
        }

        return $data;
    }
}
