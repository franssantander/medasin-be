<?php

use App\Jobs\Letter\GenerateLetterExport;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('queues a new export revision after a letter is edited', function (): void {
    Queue::fake([GenerateLetterExport::class]);
    $user = User::factory()->create();
    Passport::actingAs($user);
    $uuid = $this->postJson(route('letters.store'), [
        'title' => 'Original letter',
        'content' => '{"version":1,"blocks":[{"type":"paragraph","content":"Original words."}]}',
    ])->assertCreated()->json('data.uuid');
    $letter = Letter::where('uuid', $uuid)->sole();
    $firstUuid = $this->postJson(route('letters.exports.store', $letter), ['format' => 'square'])
        ->assertAccepted()->json('data.uuid');
    $first = $letter->exports()->where('uuid', $firstUuid)->sole();

    $this->patchJson(route('letters.update', $letter), [
        'title' => 'Revised letter',
        'content' => '{"version":1,"blocks":[{"type":"paragraph","content":"Revised words for export."}]}',
    ])->assertOk()->assertJsonPath('data.status', 'draft');
    $secondUuid = $this->postJson(route('letters.exports.store', $letter), ['format' => 'story'])
        ->assertAccepted()->assertJsonPath('data.canvas.height', 1920)->json('data.uuid');
    $second = $letter->exports()->where('uuid', $secondUuid)->sole();
    $this->getJson(route('letters.exports.index', $letter))->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.uuid', $secondUuid);

    expect($first->source_hash)->not->toBe($second->source_hash);
    $this->assertDatabaseHas('letters', ['id' => $letter->id, 'content_text' => 'Revised words for export.']);
    $this->assertDatabaseHas('letter_exports', ['id' => $first->id, 'format' => 'square', 'status' => 'queued']);
    $this->assertDatabaseHas('letter_exports', ['id' => $second->id, 'format' => 'story', 'status' => 'queued']);
    Queue::assertPushed(GenerateLetterExport::class, 2);
    Queue::assertPushed(GenerateLetterExport::class, fn (GenerateLetterExport $job): bool => $job->letterExport->is($second));
});
