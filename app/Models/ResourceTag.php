<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ResourceTag extends Model
{
    use HasUuid;

    protected $fillable = ['user_id', 'name', 'normalized_name'];
}
