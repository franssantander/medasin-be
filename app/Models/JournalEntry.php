<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'content', 'content_text', 'focus_session_id'])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class)
            ->withTimestamps();
    }

    public function focusSession(): BelongsTo
    {
        return $this->belongsTo(FocusSession::class);
    }
}
