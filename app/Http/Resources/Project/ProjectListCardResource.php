<?php

namespace App\Http\Resources\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectListCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dueDate = $this->due_date;
        $isOverdue = $dueDate !== null && $dueDate->isBefore(today());

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'background' => $this->background,
            'status' => 'not_started',
            'progress_percentage' => 0,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $dueDate?->toDateString(),
            'is_overdue' => $isOverdue,
            'days_overdue' => $isOverdue ? (int) $dueDate->diffInDays(today()) : null,
            'archived_at' => $this->archived_at?->toISOString(),
            'area' => $this->area ? [
                'uuid' => $this->area->uuid,
                'name' => $this->area->name,
                'slug' => $this->area->slug,
                'icon' => $this->area->icon,
            ] : null,
            'goals' => [
                'count' => $this->area?->goals_count ?? 0,
                'url' => $this->area ? route('area.goals.index', $this->area) : null,
            ],
        ];
    }
}
