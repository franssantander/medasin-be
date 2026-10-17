<?php

namespace App\Models;

use App\Enum\BillingInterval;
use App\Enum\Currency;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'currency', 'billing_interval', 'price_minor', 'is_active', 'limits'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public function assignments(): HasMany
    {
        return $this->hasMany(PlanAssignment::class);
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'billing_interval' => BillingInterval::class,
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'limits' => 'array',
        ];
    }
}
