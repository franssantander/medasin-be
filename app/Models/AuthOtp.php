<?php

namespace App\Models;

use App\Enum\AuthOtpPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'purpose', 'code_hash', 'version', 'expires_at', 'failed_attempts', 'recent_failures', 'recent_failures_started_at', 'locked_until', 'recent_sends', 'recent_sends_started_at', 'sent_at', 'consumed_at'])]
class AuthOtp extends Model
{
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'purpose' => AuthOtpPurpose::class,
            'expires_at' => 'datetime',
            'failed_attempts' => 'integer',
            'recent_failures' => 'integer',
            'recent_failures_started_at' => 'datetime',
            'locked_until' => 'datetime',
            'recent_sends' => 'integer',
            'recent_sends_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
