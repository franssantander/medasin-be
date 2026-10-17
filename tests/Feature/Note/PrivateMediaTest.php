<?php

use App\Models\Letter;
use App\Models\LetterExport;
use App\Models\NoteMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

it('stores note media privately and serves it only to its owner', function (): void {
    $owner = User::factory()->create();
    $note = $owner->standaloneNotes()->create(['title' => 'Garden', 'content' => 'Notes']);
    Passport::actingAs($owner);

    $response = $this->postJson(route('notes.media.store', $note->uuid), [
        'file' => UploadedFile::fake()->create('frost.png', 12, 'image/png'),
    ])->assertCreated();
    $media = NoteMedia::query()->where('uuid', $response->json('data.uuid'))->sole();

    $response->assertJsonPath('data.url', '/api/v1/notes/media/'.$media->uuid);
    Storage::disk('local')->assertExists($media->path);
    Storage::disk('public')->assertMissing($media->path);

    $this->get($response->json('data.url'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    Passport::actingAs(User::factory()->create());
    $this->getJson(route('notes.media.show', $media))->assertNotFound();
});

it('requires sign-in to read media', function (): void {
    $owner = User::factory()->create();
    $note = $owner->standaloneNotes()->create(['title' => 'Garden', 'content' => 'Notes']);
    $media = $note->media()->create(['path' => 'notes/x/y/a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 3]);
    Storage::disk('local')->put($media->path, 'png');

    $this->getJson(route('notes.media.show', $media))->assertUnauthorized();
});

it('serves area note media and letter media to their owner', function (): void {
    $owner = User::factory()->create();
    $area = $owner->areas()->create(['name' => 'Home']);
    Passport::actingAs($owner);
    $noteUuid = $this->postJson(route('area.notes.store', $area), ['title' => 'Plants', 'content' => 'Water'])->json('data.uuid');
    $letter = Letter::factory()->for($owner)->create();

    $noteUrl = $this->postJson(route('area.notes.media.store', [$area, $noteUuid]), ['file' => UploadedFile::fake()->create('fern.png', 12, 'image/png')])
        ->assertCreated()->json('data.url');
    $letterUrl = $this->postJson(route('letters.media.store', $letter->uuid), ['file' => UploadedFile::fake()->create('cover.png', 12, 'image/png')])
        ->assertCreated()->json('data.url');

    $this->get($noteUrl)->assertOk();
    $this->get($letterUrl)->assertOk();
    expect($letterUrl)->toStartWith('/api/v1/letters/media/');
});

it('still serves media uploaded before it became private', function (): void {
    $owner = User::factory()->create();
    $note = $owner->standaloneNotes()->create(['title' => 'Old', 'content' => 'Notes']);
    $media = $note->media()->create(['path' => 'notes/old/a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 3]);
    Storage::disk('public')->put($media->path, 'png');
    Passport::actingAs($owner);

    $this->get(route('notes.media.show', $media))->assertOk();
});

it('moves legacy media off the public disk and rewrites the saved URLs', function (): void {
    $owner = User::factory()->create();
    $note = $owner->standaloneNotes()->create(['title' => 'Old', 'content' => 'placeholder']);
    $noteMedia = $note->media()->create(['path' => "notes/{$owner->uuid}/{$note->uuid}/photo.png", 'original_name' => 'photo.png', 'mime_type' => 'image/png', 'size' => 3]);
    $letter = Letter::factory()->for($owner)->create(['content' => 'placeholder']);
    $letterMedia = $letter->media()->create(['path' => "letters/{$owner->uuid}/{$letter->uuid}/cover.png", 'original_name' => 'cover.png', 'mime_type' => 'image/png', 'size' => 3]);
    Storage::disk('public')->put($noteMedia->path, 'png');
    Storage::disk('public')->put($letterMedia->path, 'png');

    $noteContent = json_encode([['type' => 'image', 'props' => ['url' => "http://localhost:8000/storage/{$noteMedia->path}"]]], JSON_UNESCAPED_SLASHES);
    $letterContent = json_encode([['type' => 'image', 'props' => ['url' => "http://localhost:8000/storage/{$letterMedia->path}"]]]);
    DB::table('notes')->where('id', $note->id)->update(['content' => $noteContent]);
    DB::table('letters')->where('id', $letter->id)->update(['content' => $letterContent]);
    $export = LetterExport::factory()->for($letter)->create([
        'pages' => [['cover' => ['hero_image_url' => "/storage/{$letterMedia->path}"]]],
    ]);

    $this->artisan('media:privatize', ['--dry-run' => true])->assertSuccessful();
    Storage::disk('public')->assertExists($noteMedia->path);
    expect(DB::table('notes')->where('id', $note->id)->value('content'))->toBe($noteContent);

    $this->artisan('media:privatize')
        ->expectsOutput('Moved 2 file(s) to private storage and rewrite references in 3 record(s).')
        ->assertSuccessful();

    foreach ([$noteMedia, $letterMedia] as $media) {
        Storage::disk('local')->assertExists($media->path);
        Storage::disk('public')->assertMissing($media->path);
    }
    expect(DB::table('notes')->where('id', $note->id)->value('content'))
        ->toBe(json_encode([['type' => 'image', 'props' => ['url' => $noteMedia->url()]]], JSON_UNESCAPED_SLASHES))
        ->and(json_decode(DB::table('letters')->where('id', $letter->id)->value('content'), true)[0]['props']['url'])
        ->toBe($letterMedia->url())
        ->and($export->fresh()->pages[0]['cover']['hero_image_url'])->toBe($letterMedia->url());

    $this->artisan('media:privatize')
        ->expectsOutput('Moved 0 file(s) to private storage and rewrite references in 0 record(s).')
        ->assertSuccessful();
});

it('leaves other accounts content untouched when privatizing media', function (): void {
    $owner = User::factory()->create();
    $note = $owner->standaloneNotes()->create(['title' => 'Mine', 'content' => 'x']);
    $media = $note->media()->create(['path' => 'notes/a/b/shared.png', 'original_name' => 'shared.png', 'mime_type' => 'image/png', 'size' => 3]);
    $otherNote = User::factory()->create()->standaloneNotes()->create(['title' => 'Theirs', 'content' => 'see /storage/notes/a/b/shared.png']);

    $this->artisan('media:privatize')->assertSuccessful();

    expect($otherNote->fresh()->content)->toBe('see /storage/notes/a/b/shared.png')
        ->and($media->exists)->toBeTrue();
});
