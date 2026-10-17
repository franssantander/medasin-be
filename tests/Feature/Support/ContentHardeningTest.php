<?php

use App\Models\User;
use App\Rules\MaxBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

/** A GD-made JPEG with an EXIF block carrying orientation 6 and a GPS latitude reference after its JFIF header. */
function jpegWithLocation(): UploadedFile
{
    $file = UploadedFile::fake()->image('photo.jpg', 24, 16);
    $bytes = file_get_contents($file->getRealPath());
    $tiff = "MM\x00\x2A".pack('N', 8).pack('n', 2)
        .pack('nnN', 0x0112, 3, 1).pack('n', 6)."\x00\x00"
        .pack('nnNN', 0x8825, 4, 1, 38).pack('N', 0)
        .pack('n', 1).pack('nnN', 0x0001, 2, 2)."N\x00\x00\x00".pack('N', 0);
    $payload = "Exif\x00\x00".$tiff;
    $exif = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $app0End = 4 + unpack('n', substr($bytes, 4, 2))[1];
    file_put_contents($file->getRealPath(), substr($bytes, 0, $app0End).$exif.substr($bytes, $app0End));
    clearstatcache(true, $file->getRealPath());

    return $file;
}

it('strips location metadata from public profile images but keeps their orientation', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $upload = jpegWithLocation();
    expect(exif_read_data($upload->getRealPath()))->toHaveKey('GPSLatitudeRef');

    $this->postJson(route('profile.image.store'), ['image' => $upload])->assertOk();

    $stored = Storage::disk('public')->path($user->refresh()->profile_image_path);
    $exif = exif_read_data($stored);
    expect($exif)->not->toHaveKey('GPSLatitudeRef')
        ->and($exif['Orientation'])->toBe(6)
        ->and(getimagesize($stored)[0])->toBe(24);
});

it('strips location metadata when the media command cleans existing public images', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();
    $upload = jpegWithLocation();
    Storage::disk('public')->put("profiles/{$user->uuid}/old.jpg", file_get_contents($upload->getRealPath()));
    $user->forceFill(['profile_image_path' => "profiles/{$user->uuid}/old.jpg"])->save();
    $path = Storage::disk('public')->path($user->profile_image_path);

    $this->artisan('media:strip-metadata', ['--dry-run' => true])->expectsOutput('Would strip metadata from 1 of 1 public image(s).')->assertSuccessful();
    expect(exif_read_data($path))->toHaveKey('GPSLatitudeRef');
    $this->artisan('media:strip-metadata')->expectsOutput('Stripped metadata from 1 of 1 public image(s).')->assertSuccessful();
    expect(exif_read_data($path))->not->toHaveKey('GPSLatitudeRef');
});

it('rejects resource attachments a browser or operating system would run', function (UploadedFile $file): void {
    Storage::fake('local');
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('resource.store'), ['title' => 'Unsafe', 'files' => [$file]])
        ->assertUnprocessable()->assertJsonValidationErrors('files.0');
    $this->assertDatabaseEmpty('resources');
})->with([
    'html page' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('page.html', '<p>hello</p>'),
    'windows program' => fn (): UploadedFile => UploadedFile::fake()->create('setup.exe', 10),
    'svg image' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    // A real upload, so the type is detected from the content rather than the misleading name.
    'html disguised as an image' => function (): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');

        return new UploadedFile($path, 'photo.png', null, null, true);
    },
]);

it('keeps accepting ordinary attachments and downloads them inside a sandbox', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    Passport::actingAs($user);

    $uuid = $this->postJson(route('resource.store'), [
        'title' => 'Reading',
        'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'plain notes')],
    ])->assertCreated()->json('data.uuid');
    $resource = $user->resources()->where('uuid', $uuid)->sole();

    $this->getJson(route('resource.attachments.show', [$resource, $resource->attachments()->sole()->uuid]))
        ->assertOk()->assertDownload('notes.txt')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('caps document bodies and descriptions that quotas do not count', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('notes.store'), ['title' => 'Huge', 'content' => str_repeat('a', MaxBytes::DOCUMENT + 1)])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
    $this->postJson(route('project.store'), ['name' => 'Wordy', 'description' => str_repeat('a', 10001)])
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->postJson(route('project.store'), ['name' => 'Concise', 'description' => str_repeat('a', 10000)])
        ->assertCreated();
});

it('adds baseline security headers to API responses', function (): void {
    $this->getJson(route('plan.index'))->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeaderMissing('Strict-Transport-Security');
    $this->getJson(str_replace('http://', 'https://', route('plan.index')))->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('only answers CORS requests from the configured frontend origin', function (): void {
    config(['cors.allowed_origins' => ['https://app.example.com']]);
    $preflight = fn (string $origin) => $this->call('OPTIONS', route('plan.index'), server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($preflight('https://app.example.com')->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and($preflight('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example.com');
    config(['cors.allowed_origins' => []]);
    expect($preflight('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
