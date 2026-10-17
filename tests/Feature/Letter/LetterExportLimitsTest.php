<?php

use App\Models\Letter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

/** @return list<array<string, mixed>> */
function exportPages(int $count, string $body = 'Body text.'): array
{
    return array_map(fn (int $index): array => [
        'uuid' => sprintf('%08d-0000-4000-8000-%012d', $index, $index),
        'layout' => $index === 1 ? 'cover' : 'body',
        'text_scale' => 1,
        'text_scale_mode' => 'auto',
        'title' => $index === 1 ? 'Cover' : null,
        'subtitle' => null,
        'blocks' => $index === 1 ? [] : [['type' => 'paragraph', 'content' => $body]],
    ], range(1, $count));
}

/** @return array<string, mixed> */
function exportCover(array $overrides = []): array
{
    return [
        'theme' => 'light',
        'show_logo' => true,
        'subheader' => null,
        'description_blocks' => [['type' => 'paragraph', 'content' => 'A short excerpt.']],
        'author_name' => null,
        'date_label' => null,
        'avatar_url' => null,
        'hero_image_url' => null,
        'section_order' => ['header', 'title', 'entry', 'author'],
        ...$overrides,
    ];
}

beforeEach(function (): void {
    Queue::fake();
    $this->letter = Letter::factory()->for($user = User::factory()->create())->create();
    Passport::actingAs($user);
});

it('caps client-prepared exports at the configured page limit', function (): void {
    config(['letters.max_client_pages' => 12]);

    $this->postJson(route('letters.exports.store', $this->letter->uuid), ['format' => 'portrait', 'pages' => exportPages(12)])
        ->assertCreated();
    $this->postJson(route('letters.exports.store', $this->letter->uuid), ['format' => 'portrait', 'pages' => exportPages(13)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pages');
});

it('rejects an export whose pages are too large to store', function (): void {
    $this->postJson(route('letters.exports.store', $this->letter->uuid), [
        'format' => 'portrait',
        'pages' => exportPages(3, str_repeat('a', 600 * 1024)),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.pages.0', 'This export is too large. Shorten the letter or split it before exporting.');
});

it('accepts web and in-app cover image URLs but rejects other schemes', function (?string $url, bool $valid): void {
    $pages = exportPages(2);
    $pages[0]['cover'] = exportCover(['hero_image_url' => $url, 'section_order' => ['header', 'title', 'entry', 'author', 'hero']]);

    $response = $this->postJson(route('letters.exports.store', $this->letter->uuid), ['format' => 'portrait', 'pages' => $pages]);

    $valid ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('pages.0.cover.hero_image_url');
})->with([
    'https' => ['https://example.com/cover.png', true],
    'uploaded media' => ['/api/v1/letters/media/00000000-0000-4000-8000-000000000001', true],
    'legacy public storage' => ['/storage/letters/a/b/cover.png', true],
    'javascript' => ['javascript:alert(1)', false],
    'data' => ['data:image/png;base64,AAAA', false],
    'protocol-relative' => ['//evil.example/cover.png', false],
]);
