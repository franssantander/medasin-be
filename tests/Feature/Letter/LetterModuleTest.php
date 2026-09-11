<?php

namespace Tests\Feature\Letter;

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
use App\Enum\LetterStatus;
use App\Jobs\Letter\GenerateLetterExport;
use App\Models\Letter;
use App\Models\LetterExport;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Letter\LetterPaginationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LetterModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_letter_requests_return_401(): void
    {
        $this->getJson(route('letters.index'))->assertUnauthorized();
        $this->postJson(route('letters.store'))->assertUnauthorized();
    }

    public function test_valid_letter_creates_rich_content_and_reading_metadata(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ava',
            'last_name' => 'Santos',
            'username' => 'avasantos',
        ]);
        $content = $this->document('A clear message for readers.');
        Passport::actingAs($user);

        $response = $this->postJson(route('letters.store'), [
            'title' => 'A letter for readers',
            'subtitle' => 'A short note',
            'content' => $content,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'A letter for readers')
            ->assertJsonPath('data.subtitle', 'A short note')
            ->assertJsonPath('data.content', $content)
            ->assertJsonPath('data.content_preview', 'A clear message for readers.')
            ->assertJsonPath('data.word_count', 5)
            ->assertJsonPath('data.read_time_minutes', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.author.name', 'Ava Santos')
            ->assertJsonPath('data.author.handle', '@avasantos')
            ->assertJsonPath('data.latest_export', null);

        $letter = Letter::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertModelExists($letter);
        $this->assertDatabaseHas('letters', [
            'id' => $letter->getKey(),
            'user_id' => $user->getKey(),
            'content_text' => 'A clear message for readers.',
            'word_count' => 5,
            'read_time_minutes' => 1,
            'status' => LetterStatus::DRAFT->value,
        ]);
    }

    public function test_empty_blocknote_document_creates_a_title_only_draft(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->postJson(route('letters.store'), [
            'title' => 'An unfinished letter',
            'content' => $this->document(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.word_count', 0)
            ->assertJsonPath('data.read_time_minutes', 0)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('letters', [
            'user_id' => $user->getKey(),
            'content_text' => null,
            'word_count' => 0,
            'read_time_minutes' => 0,
        ]);
    }

    public function test_listing_is_paginated_filtered_and_scoped_to_active_letters(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $older = Letter::factory()->for($user)->create([
            'title' => 'Older draft',
            'updated_at' => '2026-09-09 12:00:00',
        ]);
        $newer = Letter::factory()->for($user)->create([
            'title' => 'Exported letter',
            'status' => LetterStatus::EXPORTED,
            'exported_at' => '2026-09-10 12:00:00',
            'updated_at' => '2026-09-10 12:00:00',
        ]);
        Letter::factory()->for($user)->create(['title' => 'Deleted letter'])->delete();
        Letter::factory()->for($otherUser)->create(['title' => 'Private letter']);
        Passport::actingAs($user);

        $this->getJson(route('letters.index', ['status' => 'exported', 'per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.uuid', $newer->uuid)
            ->assertJsonPath('data.data.0.title', 'Exported letter')
            ->assertJsonMissingPath('data.data.0.content');

        $this->getJson(route('letters.index', ['per_page' => 100]))
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.1.uuid', $older->uuid);
    }

    public function test_update_recalculates_metadata_and_resets_an_exported_letter_to_draft(): void
    {
        $user = User::factory()->create();
        $letter = Letter::factory()->for($user)->create([
            'status' => LetterStatus::EXPORTED,
            'exported_at' => now(),
        ]);
        Passport::actingAs($user);

        $content = $this->document('Updated words stay visible in the editor.');
        $this->patchJson(route('letters.update', $letter->uuid), [
            'subtitle' => 'Updated subtitle',
            'content' => $content,
        ])
            ->assertOk()
            ->assertJsonPath('data.subtitle', 'Updated subtitle')
            ->assertJsonPath('data.content', $content)
            ->assertJsonPath('data.content_preview', 'Updated words stay visible in the editor.')
            ->assertJsonPath('data.word_count', 7)
            ->assertJsonPath('data.read_time_minutes', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.exported_at', null);

        $this->assertDatabaseHas('letters', [
            'id' => $letter->getKey(),
            'content_text' => 'Updated words stay visible in the editor.',
            'word_count' => 7,
            'status' => LetterStatus::DRAFT->value,
            'exported_at' => null,
        ]);
    }

    public function test_invalid_documents_and_export_formats_return_422(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->postJson(route('letters.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'content']);
        $this->postJson(route('letters.store'), [
            'title' => 'Invalid document',
            'content' => '{"version":2,"blocks":[]}',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
        $this->postJson(route('letters.store'), [
            'title' => 'Invalid blocks',
            'content' => '{"version":1,"blocks":["not a block"]}',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        $letter = Letter::factory()->for($user)->create();
        $this->postJson(route('letters.exports.store', $letter->uuid), ['format' => 'landscape'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');

        $this->assertDatabaseCount('letters', 1);
    }

    public function test_users_cannot_read_update_delete_or_export_another_users_letter(): void
    {
        $owner = User::factory()->create();
        $letter = Letter::factory()->for($owner)->create();
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('letters.show', $letter->uuid))->assertNotFound();
        $this->patchJson(route('letters.update', $letter->uuid), ['title' => 'No access'])->assertNotFound();
        $this->deleteJson(route('letters.destroy', $letter->uuid))->assertNotFound();
        $this->postJson(route('letters.exports.store', $letter->uuid))->assertNotFound();

        $this->assertModelExists($letter);
        $this->assertDatabaseCount('letter_exports', 0);
        $this->assertDatabaseCount('trash_entries', 0);
    }

    public function test_export_request_is_queued_with_the_selected_canvas_profile(): void
    {
        $user = User::factory()->create();
        $letter = Letter::factory()->for($user)->create();
        Queue::fake();
        Passport::actingAs($user);

        $response = $this->postJson(route('letters.exports.store', $letter->uuid), [
            'format' => LetterExportFormat::SQUARE->value,
        ])
            ->assertAccepted()
            ->assertJsonPath('data.format', 'square')
            ->assertJsonPath('data.canvas.width', 1080)
            ->assertJsonPath('data.canvas.height', 1080)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.page_count', null)
            ->assertJsonPath('data.pages', null);

        $export = LetterExport::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

        Queue::assertPushed(GenerateLetterExport::class, fn (GenerateLetterExport $job): bool => $job->letterExport->is($export));
        $this->assertDatabaseHas('letter_exports', [
            'id' => $export->getKey(),
            'letter_id' => $letter->getKey(),
            'format' => LetterExportFormat::SQUARE->value,
            'status' => LetterExportStatus::QUEUED->value,
        ]);
    }

    public function test_export_job_creates_cover_and_final_signature_pages_and_marks_current_letter_exported(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Mina',
            'last_name' => 'Reyes',
            'username' => 'minareads',
        ]);
        $letter = Letter::factory()->for($user)->create([
            'title' => 'A public note',
            'content' => $this->document('The final paragraph belongs to the author.'),
        ]);
        $export = LetterExport::factory()->for($letter)->create([
            'format' => LetterExportFormat::PORTRAIT,
            'canvas_width' => 1080,
            'canvas_height' => 1350,
            'source_hash' => $letter->sourceHash(),
        ]);

        (new GenerateLetterExport($export))->handle($this->app->make(LetterPaginationService::class));

        $export->refresh();
        $letter->refresh();
        $this->assertSame(LetterExportStatus::READY, $export->status);
        $this->assertSame(LetterStatus::EXPORTED, $letter->status);
        $this->assertSame(2, $export->page_count);
        $this->assertSame('cover', $export->pages[0]['kind']);
        $this->assertSame('final', $export->pages[1]['kind']);
        $this->assertSame('Mina Reyes', $export->pages[1]['signature']['name']);
        $this->assertSame('@minareads', $export->pages[1]['signature']['handle']);
        $this->assertSame('A public note', $export->pages[0]['title']);
    }

    public function test_export_job_caps_long_content_at_ten_pages_with_a_continuation_label(): void
    {
        $user = User::factory()->create();
        $paragraphs = array_fill(0, 120, str_repeat('A thoughtful sentence for a shareable letter. ', 8));
        $letter = Letter::factory()->for($user)->create([
            'content' => $this->document(...$paragraphs),
        ]);
        $export = LetterExport::factory()->for($letter)->create([
            'source_hash' => $letter->sourceHash(),
        ]);

        (new GenerateLetterExport($export))->handle($this->app->make(LetterPaginationService::class));

        $export->refresh();
        $this->assertSame(10, $export->page_count);
        $this->assertTrue($export->pages[9]['truncated']);
        $this->assertSame('Continued in the app', $export->pages[9]['continuation_label']);
        $this->assertSame('final', $export->pages[9]['kind']);
    }

    public function test_stale_export_does_not_mark_an_edited_letter_as_exported(): void
    {
        $user = User::factory()->create();
        $letter = Letter::factory()->for($user)->create();
        $export = LetterExport::factory()->for($letter)->create([
            'source_hash' => $letter->sourceHash(),
        ]);
        $letter->update(['title' => 'Changed after export was requested']);

        (new GenerateLetterExport($export))->handle($this->app->make(LetterPaginationService::class));

        $export->refresh();
        $letter->refresh();
        $this->assertSame(LetterExportStatus::READY, $export->status);
        $this->assertSame(LetterStatus::DRAFT, $letter->status);
        $this->assertNull($letter->exported_at);
    }

    public function test_deleting_a_letter_uses_trash_and_restores_it(): void
    {
        $user = User::factory()->create();
        $letter = Letter::factory()->for($user)->create(['title' => 'Disposable letter']);
        Passport::actingAs($user);

        $this->deleteJson(route('letters.destroy', $letter->uuid))
            ->assertOk()
            ->assertJsonPath('message', 'Letter moved to Trash. It will be permanently deleted after 30 days.');

        $this->assertSoftDeleted('letters', ['uuid' => $letter->uuid]);
        $trashEntry = TrashEntry::query()->where('item_type', 'letter')->sole();

        $this->getJson(route('trash.index'))
            ->assertOk()
            ->assertJsonPath('data.data.0.type', 'letter')
            ->assertJsonPath('data.data.0.title', 'Disposable letter');
        $this->postJson(route('trash.restore', $trashEntry->uuid))->assertOk();

        $this->assertNotSoftDeleted('letters', ['uuid' => $letter->uuid]);
        $this->assertDatabaseMissing('trash_entries', ['id' => $trashEntry->getKey()]);
    }

    private function document(string ...$paragraphs): string
    {
        return json_encode([
            'version' => 1,
            'blocks' => array_map(
                fn (string $paragraph): array => [
                    'type' => 'paragraph',
                    'content' => $paragraph,
                ],
                $paragraphs,
            ),
        ], JSON_THROW_ON_ERROR);
    }
}
