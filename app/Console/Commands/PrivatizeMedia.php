<?php

namespace App\Console\Commands;

use App\Models\LetterMedia;
use App\Models\NoteMedia;
use App\Models\User;
use App\Services\ApiReadCacheService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Moves note and letter media that predates private storage off the public disk, and rewrites the old public
 * URLs saved inside each owner's content to the authenticated media routes. Safe to run more than once.
 */
#[Signature('media:privatize {--dry-run : Report what would change without moving files or editing content}')]
#[Description('Move note and letter media to private storage and rewrite their saved URLs')]
class PrivatizeMedia extends Command
{
    /** Raw text columns, scoped to one owner, that can contain an image or video URL. */
    private const CONTENT_COLUMNS = [
        ['notes', 'content'],
        ['letters', 'content'],
        ['journal_entries', 'content'],
        ['resources', 'content'],
    ];

    public function handle(ApiReadCacheService $cache): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $rewritten = 0;

        User::query()->orderBy('id')->each(function (User $user) use ($cache, $dryRun, &$moved, &$rewritten): void {
            $media = $this->mediaFor($user);
            if ($media === []) {
                return;
            }

            foreach ($media as $item) {
                if ($this->moveToPrivateDisk($item, $dryRun)) {
                    $moved++;
                }
            }

            $replacements = collect($media)->mapWithKeys(fn (Model $item): array => [$item->path => $item->url()])->all();
            $changed = $this->rewriteReferences($user, $replacements, $dryRun);
            $rewritten += $changed;
            if ($changed > 0 && ! $dryRun) {
                $cache->invalidateUser($user);
            }
        });

        $prefix = $dryRun ? 'Would move' : 'Moved';
        $this->info("{$prefix} {$moved} file(s) to private storage and rewrite references in {$rewritten} record(s).");

        return self::SUCCESS;
    }

    /** @return list<NoteMedia|LetterMedia> */
    private function mediaFor(User $user): array
    {
        $notes = NoteMedia::query()->whereIn('note_id', DB::table('notes')->where('user_id', $user->getKey())->select('id'))->get();
        $letters = LetterMedia::query()->whereIn('letter_id', DB::table('letters')->where('user_id', $user->getKey())->select('id'))->get();

        return [...$notes->all(), ...$letters->all()];
    }

    private function moveToPrivateDisk(NoteMedia|LetterMedia $media, bool $dryRun): bool
    {
        $public = Storage::disk('public');
        if (! $public->exists($media->path)) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        $private = Storage::disk($media::DISK);
        if (! $private->exists($media->path)) {
            $stream = $public->readStream($media->path);
            $private->writeStream($media->path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($private->size($media->path) === $public->size($media->path)) {
            $public->delete($media->path);
        }

        return true;
    }

    /** @param array<string, string> $replacements storage path => new media URL */
    private function rewriteReferences(User $user, array $replacements, bool $dryRun): int
    {
        $changed = 0;

        DB::transaction(function () use ($user, $replacements, $dryRun, &$changed): void {
            $boards = DB::table('boards')->where('user_id', $user->getKey())->select('id');
            $letters = DB::table('letters')->where('user_id', $user->getKey())->select('id');
            $sources = [
                ...array_map(fn (array $column): array => [
                    DB::table($column[0])->where('user_id', $user->getKey()),
                    $column[1],
                ], self::CONTENT_COLUMNS),
                [DB::table('board_tasks')->whereIn('board_id', $boards), 'description'],
                [DB::table('letter_exports')->whereIn('letter_id', $letters), 'pages'],
            ];

            foreach ($sources as [$query, $column]) {
                $query->whereNotNull($column)->select(['id', $column])->orderBy('id')->get()
                    ->each(function (object $row) use ($query, $column, $replacements, $dryRun, &$changed): void {
                        $updated = $this->replaceUrls((string) $row->{$column}, $replacements);
                        if ($updated === $row->{$column}) {
                            return;
                        }
                        $changed++;
                        if (! $dryRun) {
                            // Raw update: the content is unchanged apart from the URL, so updated_at stays put.
                            DB::table($query->from)->where('id', $row->id)->update([$column => $updated]);
                        }
                    });
            }
        });

        return $changed;
    }

    /**
     * Replaces any URL ending in /storage/{path} (absolute or relative, with plain or JSON-escaped slashes).
     *
     * @param  array<string, string>  $replacements
     */
    private function replaceUrls(string $text, array $replacements): string
    {
        foreach ($replacements as $path => $url) {
            if (! str_contains($text, basename($path))) {
                continue;
            }

            $plain = '~(?:https?://[^"\'\s<>]*?)?/storage/'.preg_quote($path, '~').'(?![\w.-])~';
            $text = preg_replace_callback($plain, fn (): string => $url, $text) ?? $text;

            $escapedPath = str_replace('/', '\\/', $path);
            $escaped = '~(?:https?:\\\\/\\\\/[^"\'\s<>]*?)?\\\\/storage\\\\/'.preg_quote($escapedPath, '~').'(?![\w.-])~';
            $text = preg_replace_callback($escaped, fn (): string => str_replace('/', '\\/', $url), $text) ?? $text;
        }

        return $text;
    }
}
