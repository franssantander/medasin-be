<?php

namespace App\Data\Resource;

use App\Models\Resource;
use Spatie\LaravelData\Data;

class ResourceData extends Data
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $user_id,
        public string $title,
        public ?string $type,
        public ?string $description,
        public ?string $url,
        public ?string $author,
        public ?string $source,
        public ?string $icon,
        public ?string $background,
        public bool $is_favorite,
        public ?string $archived_at,
        public ?string $created_at,
        public ?string $updated_at,
        public ?string $deleted_at,
    ) {}

    public static function fromModel(Resource $resource): self
    {
        return self::from($resource->toArray());
    }
}
