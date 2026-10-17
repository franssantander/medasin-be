<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Caps how much text a document field can hold, measured in bytes (arrays as their JSON encoding),
 * because storage quotas only count uploaded files.
 */
class MaxBytes implements ValidationRule
{
    /** Default ceiling for a saved document such as a note, letter, journal entry, or resource body. */
    public const DOCUMENT = 2 * 1024 * 1024;

    public function __construct(private readonly int $bytes = self::DOCUMENT) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $size = match (true) {
            is_string($value) => strlen($value),
            is_array($value) => strlen((string) json_encode($value)),
            default => 0,
        };

        if ($size > $this->bytes) {
            $fail('The :attribute is too large to save. Split it into smaller documents.');
        }
    }
}
