<?php

namespace App\Http\Requests\Letter;

use Closure;
use Illuminate\Validation\Validator;

/** Bounds on client-prepared export pages, which are stored as-is and also rewrite the letter on PATCH. */
final class LetterExportLimits
{
    public const MAX_BLOCKS_PER_PAGE = 200;

    public const MAX_PAGES_BYTES = 1024 * 1024;

    /** Browser-measured exports may exceed the server paginator's 10-page cap, so this is a separate, looser bound. */
    public static function maxPages(): int
    {
        return (int) config('letters.max_client_pages');
    }

    /** Cover images may only point at web URLs or the app's own paths (uploaded media and profile images). */
    public static function imageUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            $isAppPath = is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//');
            $scheme = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_SCHEME)) : '';
            if (! $isAppPath && ! in_array($scheme, ['http', 'https'], true)) {
                $fail('The image must be a web address or an uploaded image.');
            }
        };
    }

    /** @param array<int, mixed> $pages */
    public static function validate(Validator $validator, array $pages): void
    {
        if (strlen((string) json_encode($pages)) > self::MAX_PAGES_BYTES) {
            $validator->errors()->add('pages', 'This export is too large. Shorten the letter or split it before exporting.');
        }
    }
}
