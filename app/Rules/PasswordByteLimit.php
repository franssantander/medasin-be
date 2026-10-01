<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PasswordByteLimit implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('The :attribute must not contain null characters.');
        }

        if (is_string($value) && strlen($value) > 72) {
            $fail('The :attribute must not exceed 72 bytes.');
        }
    }
}
