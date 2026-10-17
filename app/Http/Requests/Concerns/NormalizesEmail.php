<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Trims and lowercases the submitted email before validation, matching how User stores it, so an
 * address typed with different capitals still finds (and cannot duplicate) the same account.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
