<?php

namespace App\Models;

use App\Enum\Currency;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'currency', 'price', 'is_active', 'limits'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'price' => 'integer',
            'is_active' => 'boolean',
            'limits' => 'array',
        ];
    }
}
