<?php

namespace App\Models;

use App\Enum\BillingInterval;
use App\Enum\Currency;
use App\Enum\PlanAssignmentStatus;
use App\Enum\PlanGrantType;
use App\Models\Concerns\HasUuid;
use Database\Factories\PlanAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'plan_id', 'status', 'grant_type', 'starts_at', 'ends_at', 'source', 'source_reference', 'price_minor', 'currency', 'billing_interval'])]
#[Hidden(['source', 'source_reference'])]
class PlanAssignment extends Model
{
    /** @use HasFactory<PlanAssignmentFactory> */
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => PlanAssignmentStatus::class,
            'grant_type' => PlanGrantType::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'price_minor' => 'integer',
            'currency' => Currency::class,
            'billing_interval' => BillingInterval::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }
}
