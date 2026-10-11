<?php

use App\Enum\LetterStatus;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

function libraryLetter(User $user, array $attributes = []): Letter
{
    return Letter::factory()->for($user)->create($attributes);
}

it('filters the library by draft and done and returns tab counts', function (): void {
    $user = User::factory()->create();
    $draft = libraryLetter($user, ['title' => 'Still drafting', 'updated_at' => '2026-10-01 10:00:00']);
    $done = libraryLetter($user, ['title' => 'All finished', 'done_at' => now(), 'updated_at' => '2026-10-02 10:00:00']);
    libraryLetter($user, ['title' => 'In the bin', 'done_at' => now()])->delete();
    libraryLetter(User::factory()->create(), ['title' => 'Someone else', 'done_at' => now()]);
    Passport::actingAs($user);

    $this->getJson(route('letters.index'))->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.data.0.uuid', $done->uuid)
        ->assertJsonPath('data.data.0.done_at', fn (?string $value): bool => $value !== null)
        ->assertJsonPath('data.data.1.done_at', null)
        ->assertJsonPath('data.counts', ['all' => 2, 'draft' => 1, 'done' => 1]);

    $this->getJson(route('letters.index', ['status' => 'draft']))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $draft->uuid)
        ->assertJsonPath('data.counts', ['all' => 2, 'draft' => 1, 'done' => 1]);

    $this->getJson(route('letters.index', ['status' => 'done']))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $done->uuid);
});

it('searches titles, cover text, and body text across every page', function (): void {
    $user = User::factory()->create();
    $title = libraryLetter($user, ['title' => 'Morning RIVER walk', 'updated_at' => '2026-10-03 10:00:00']);
    $subtitle = libraryLetter($user, ['title' => 'Cover', 'subtitle' => 'Down by the river', 'done_at' => now(), 'updated_at' => '2026-10-02 10:00:00']);
    $body = libraryLetter($user, ['title' => 'Body', 'content_text' => 'The river was cold.', 'updated_at' => '2026-10-01 10:00:00']);
    libraryLetter($user, ['title' => 'Mountains', 'content_text' => 'Snow everywhere.']);
    Passport::actingAs($user);

    $this->getJson(route('letters.index', ['search' => 'river', 'per_page' => 2]))->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.last_page', 2)
        ->assertJsonPath('data.data.0.uuid', $title->uuid)
        ->assertJsonPath('data.data.1.uuid', $subtitle->uuid)
        ->assertJsonPath('data.counts', ['all' => 3, 'draft' => 2, 'done' => 1]);

    $this->getJson(route('letters.index', ['search' => 'river', 'page' => 2, 'per_page' => 2]))->assertOk()
        ->assertJsonPath('data.data.0.uuid', $body->uuid);

    $this->getJson(route('letters.index', ['search' => 'river', 'status' => 'done']))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $subtitle->uuid);
});

it('treats search wildcards as literal text', function (): void {
    $user = User::factory()->create();
    $percent = libraryLetter($user, ['title' => '100% honest']);
    libraryLetter($user, ['title' => '100 reasons']);
    libraryLetter($user, ['title' => 'snake_case', 'content_text' => null]);
    libraryLetter($user, ['title' => 'snakeXcase', 'content_text' => null]);
    Passport::actingAs($user);

    $this->getJson(route('letters.index', ['search' => '0%']))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $percent->uuid);
    $this->getJson(route('letters.index', ['search' => 'e_c']))->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.counts.all', 1);
});

it('marks a letter done and back to draft without touching its export', function (): void {
    $user = User::factory()->create();
    $letter = libraryLetter($user, ['status' => LetterStatus::EXPORTED, 'exported_at' => now()]);
    Passport::actingAs($user);

    $this->patchJson(route('letters.update', $letter), ['done' => true])->assertOk()
        ->assertJsonPath('data.status', 'exported')
        ->assertJsonPath('data.done_at', fn (?string $value): bool => $value !== null);
    $letter->refresh();
    expect($letter->done_at)->not->toBeNull()
        ->and($letter->status)->toBe(LetterStatus::EXPORTED)
        ->and($letter->exported_at)->not->toBeNull();

    $doneAt = $letter->done_at->toISOString();
    $this->patchJson(route('letters.update', $letter), ['done' => true])->assertOk()
        ->assertJsonPath('data.done_at', $doneAt);

    $this->patchJson(route('letters.update', $letter), ['done' => false])->assertOk()
        ->assertJsonPath('data.done_at', null)
        ->assertJsonPath('data.status', 'exported');
    $this->getJson(route('letters.index', ['status' => 'done']))->assertOk()
        ->assertJsonPath('data.total', 0);
});

it('keeps a done letter done when its text is edited', function (): void {
    $user = User::factory()->create();
    $letter = libraryLetter($user, [
        'status' => LetterStatus::EXPORTED,
        'exported_at' => now(),
        'done_at' => now(),
    ]);
    Passport::actingAs($user);

    $this->patchJson(route('letters.update', $letter), [
        'title' => 'Small typo fix',
        'content' => '{"version":1,"blocks":[{"type":"paragraph","content":"Fixed words."}]}',
    ])->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.exported_at', null)
        ->assertJsonPath('data.done_at', fn (?string $value): bool => $value !== null);
});

it('validates library filters and the done flag', function (): void {
    $user = User::factory()->create();
    $letter = libraryLetter($user);
    Passport::actingAs($user);

    $this->getJson(route('letters.index', ['status' => 'exported']))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson(route('letters.index', ['search' => str_repeat('a', 256)]))
        ->assertUnprocessable()->assertJsonValidationErrors('search');
    $this->patchJson(route('letters.update', $letter), ['done' => 'soon'])
        ->assertUnprocessable()->assertJsonValidationErrors('done');
});

it('does not let another user mark a letter done', function (): void {
    $letter = libraryLetter(User::factory()->create());
    Passport::actingAs(User::factory()->create());

    $this->patchJson(route('letters.update', $letter), ['done' => true])->assertNotFound();
    expect($letter->refresh()->done_at)->toBeNull();
});
