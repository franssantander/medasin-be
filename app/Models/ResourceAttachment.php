<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResourceAttachment extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = ['kind', 'url', 'path', 'original_name', 'mime_type', 'size'];

    protected $hidden = ['path'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
