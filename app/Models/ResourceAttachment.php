<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ResourceAttachment extends Model
{
    use HasUuid;

    protected $fillable = ['kind', 'url', 'path', 'original_name', 'mime_type', 'size'];

    protected $hidden = ['path'];
}
