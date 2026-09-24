<?php

namespace App\Data\Habit;

use App\Models\Habit;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class HabitResponseData extends Data
{
    /**
     * @param  array<int, string>|null  $schedule
     * @param  array<string, mixed>|null|Optional  $area
     * @param  array<int, array<string, mixed>>|Optional  $check_ins
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public int $user_id,
        public ?int $area_id,
        public string $name,
        public ?string $icon,
        public ?string $description,
        public string $frequency,
        public ?array $schedule,
        public bool $is_active,
        public ?string $created_at,
        public ?string $updated_at,
        public ?string $deleted_at,
        public array|null|Optional $area,
        public array|Optional $check_ins,
    ) {}

    public static function fromModel(Habit $habit): self
    {
        return new self(
            id: $habit->getKey(),
            uuid: $habit->uuid,
            user_id: $habit->user_id,
            area_id: $habit->area_id,
            name: $habit->name,
            icon: $habit->icon,
            description: $habit->description,
            frequency: $habit->frequency->value,
            schedule: $habit->schedule,
            is_active: $habit->is_active,
            created_at: $habit->created_at?->toISOString(),
            updated_at: $habit->updated_at?->toISOString(),
            deleted_at: $habit->deleted_at?->toISOString(),
            area: $habit->relationLoaded('area') ? $habit->area?->toArray() : Optional::create(),
            check_ins: $habit->relationLoaded('checkIns') ? $habit->checkIns->toArray() : Optional::create(),
        );
    }
}
