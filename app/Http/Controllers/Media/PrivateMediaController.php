<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\LetterMedia;
use App\Models\NoteMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Streams note and letter media to their owner only; other accounts and guests get 404. */
class PrivateMediaController extends Controller
{
    public function note(Request $request, NoteMedia $noteMedia): BinaryFileResponse
    {
        $owner = $noteMedia->note()->withTrashed()->value('user_id');
        abort_unless($owner === $request->user()->getKey(), 404);

        return $this->file($noteMedia->path, $noteMedia->mime_type, $noteMedia->original_name, NoteMedia::DISK);
    }

    public function letter(Request $request, LetterMedia $letterMedia): BinaryFileResponse
    {
        $owner = $letterMedia->letter()->withTrashed()->value('user_id');
        abort_unless($owner === $request->user()->getKey(), 404);

        return $this->file($letterMedia->path, $letterMedia->mime_type, $letterMedia->original_name, LetterMedia::DISK);
    }

    private function file(string $path, string $mimeType, ?string $name, string $disk): BinaryFileResponse
    {
        // Files uploaded before media became private stay on the public disk until media:privatize moves them.
        $disk = Storage::disk($disk)->exists($path) ? $disk : 'public';
        abort_unless(Storage::disk($disk)->exists($path), 404);

        // BinaryFileResponse honours Range requests, which video seeking needs.
        return response()->file(Storage::disk($disk)->path($path), [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.addcslashes($name ?: basename($path), '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
