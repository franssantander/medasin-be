<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects attachments that a browser would run as a page or script, or an operating system as a program,
 * judged by both the client file name and the detected content type.
 */
class SafeAttachment implements ValidationRule
{
    private const BLOCKED_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'mht', 'mhtml', 'svg', 'svgz',
        'js', 'mjs', 'cjs', 'vbs', 'vbe', 'wsf', 'wsh', 'hta',
        'exe', 'com', 'scr', 'pif', 'msi', 'msp', 'dll', 'sys', 'cpl', 'lnk', 'reg',
        'bat', 'cmd', 'ps1', 'psm1', 'sh', 'bash', 'zsh', 'command',
        'jar', 'apk', 'app', 'dmg', 'pkg', 'deb', 'rpm',
        'php', 'phtml', 'phar',
    ];

    private const BLOCKED_MIME_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/javascript', 'text/javascript', 'application/x-javascript',
        'application/x-msdownload', 'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib',
        'application/x-mach-binary', 'application/vnd.microsoft.portable-executable',
        'application/java-archive', 'application/vnd.android.package-archive',
        'application/x-sh', 'text/x-shellscript', 'application/x-httpd-php', 'text/x-php',
    ];

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $extensions = array_filter([
            strtolower($value->getClientOriginalExtension()),
            strtolower((string) $value->guessExtension()),
        ]);
        $mime = strtolower((string) $value->getMimeType());

        if (array_intersect($extensions, self::BLOCKED_EXTENSIONS) !== [] || in_array($mime, self::BLOCKED_MIME_TYPES, true)) {
            $fail('This file type cannot be attached for security reasons. Try a document, image, archive, or media file.');
        }
    }
}
