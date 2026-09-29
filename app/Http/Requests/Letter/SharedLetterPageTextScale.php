<?php

namespace App\Http\Requests\Letter;

use Illuminate\Validation\Validator;

final class SharedLetterPageTextScale
{
    /** @param array<int, mixed> $pages */
    public static function validate(Validator $validator, array $pages): void
    {
        $sharedScale = null;
        $sharedMode = null;

        foreach (array_slice($pages, 1, preserve_keys: true) as $index => $page) {
            if (! is_array($page)) {
                continue;
            }

            $scale = $page['text_scale'] ?? 1;
            $mode = $page['text_scale_mode'] ?? 'auto';
            if (! is_numeric($scale) || ! in_array($mode, ['auto', 'manual'], true)) {
                continue;
            }

            if ($sharedScale === null) {
                $sharedScale = (float) $scale;
                $sharedMode = $mode;

                continue;
            }

            if (abs((float) $scale - $sharedScale) > 0.000001) {
                $validator->errors()->add(
                    "pages.{$index}.text_scale",
                    'Every page after the cover must use the same text size.',
                );
            }

            if ($mode !== $sharedMode) {
                $validator->errors()->add(
                    "pages.{$index}.text_scale_mode",
                    'Every page after the cover must use the same text size mode.',
                );
            }
        }
    }
}
