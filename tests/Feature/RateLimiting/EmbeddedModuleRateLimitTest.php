<?php

use App\Enum\LetterExportStatus;
use App\Jobs\Letter\GenerateLetterExport;
use App\Models\Board;
use App\Models\Letter;
use App\Models\LetterExport;
use App\Models\LetterMedia;
use App\Models\NoteMedia;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('shares the Board allowance across standalone and Project board labels tasks and moves', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    Passport::actingAs($user);
    $projectUuid = $this->postJson(route('project.store'), ['name' => 'Delivery'])
        ->assertCreated()->json('data.uuid');
    $project = Project::query()->where('uuid', $projectUuid)->sole();
    $projectBoard = $project->boards()->sole();

    $boardUuid = $this->postJson(route('board.store'), ['name' => 'Personal'])
        ->assertCreated()->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '299')->json('data.uuid');
    $board = Board::query()->where('uuid', $boardUuid)->sole();
    $this->getJson(route('project.boards.show', [$project, $projectBoard]))
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '298');
    $labelUuid = $this->postJson(route('board.labels.store', $board), ['name' => 'Personal label', 'color' => 'blue'])
        ->assertCreated()->assertHeader('X-RateLimit-Remaining', '297')->json('data.uuid');
    $projectLabelUuid = $this->postJson(route('project.boards.labels.store', [$project, $projectBoard]), [
        'name' => 'Delivery label', 'color' => 'green',
    ])->assertCreated()->assertHeader('X-RateLimit-Remaining', '296')->json('data.uuid');
    $taskUuid = $this->postJson(route('board.tasks.store', $board), [
        'title' => 'Personal task', 'label_uuids' => [$labelUuid],
    ])->assertCreated()->assertHeader('X-RateLimit-Remaining', '295')->json('data.uuid');
    $projectTaskUuid = $this->postJson(route('project.boards.tasks.store', [$project, $projectBoard]), [
        'title' => 'Delivery task', 'label_uuids' => [$projectLabelUuid],
    ])->assertCreated()->assertHeader('X-RateLimit-Remaining', '294')->json('data.uuid');
    $task = $board->tasks()->where('uuid', $taskUuid)->sole();
    $projectTask = $projectBoard->tasks()->where('uuid', $projectTaskUuid)->sole();
    $this->putJson(route('board.tasks.update', [$board, $task]), [
        'title' => 'Saved personal task', 'label_uuids' => [$labelUuid],
    ])->assertOk()->assertHeader('X-RateLimit-Remaining', '293');
    $this->putJson(route('project.boards.tasks.update', [$project, $projectBoard, $projectTask]), [
        'title' => 'Saved delivery task', 'label_uuids' => [$projectLabelUuid],
    ])->assertOk()->assertHeader('X-RateLimit-Remaining', '292');
    $this->patchJson(route('board.tasks.move', [$board, $task]), ['stage' => 'done', 'position' => 0])
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '291');
    $this->patchJson(route('project.boards.tasks.move', [$project, $projectBoard, $projectTask]), ['stage' => 'done', 'position' => 0])
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '290');

    $this->assertDatabaseCount('boards', 2);
    $this->assertDatabaseCount('board_tasks', 2);
    $this->assertDatabaseHas('board_tasks', [
        'id' => $task->id, 'title' => 'Saved personal task',
        'board_stage_id' => $board->stages()->where('key', 'done')->value('id'),
    ]);
    $this->assertDatabaseHas('board_tasks', [
        'id' => $projectTask->id, 'title' => 'Saved delivery task',
        'board_stage_id' => $projectBoard->stages()->where('key', 'done')->value('id'),
    ]);
    $this->assertSame([$labelUuid], $task->labels()->pluck('uuid')->all());
    $this->assertSame([$projectLabelUuid], $projectTask->labels()->pluck('uuid')->all());
});

it('shares the Notes allowance across standalone and Area note CRUD trees and media', function (): void {
    $this->freezeTime();
    Storage::fake('public');
    $user = User::factory()->create();
    $area = $user->areas()->create(['name' => 'Writing']);
    Passport::actingAs($user);

    $this->getJson(route('notes.index'))->assertOk()
        ->assertHeader('X-RateLimit-Limit', '300')->assertHeader('X-RateLimit-Remaining', '299');
    $noteUuid = $this->postJson(route('notes.store'), ['title' => 'Personal note', 'content' => 'First draft'])
        ->assertCreated()->assertHeader('X-RateLimit-Remaining', '298')->json('data.uuid');
    $note = $user->standaloneNotes()->where('uuid', $noteUuid)->sole();
    $this->getJson(route('notes.show', $note))->assertOk()->assertHeader('X-RateLimit-Remaining', '297');
    $this->getJson(route('notes.tree'))->assertOk()->assertHeader('X-RateLimit-Remaining', '296');
    $this->patchJson(route('notes.update', $note), ['content' => 'Saved personal draft'])
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '295');
    $this->post(route('notes.media.store', $note), [
        'file' => UploadedFile::fake()->create('personal.png', 10, 'image/png'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertHeader('X-RateLimit-Remaining', '294');
    $areaNoteUuid = $this->postJson(route('area.notes.store', $area), ['title' => 'Area note', 'content' => 'Area draft'])
        ->assertCreated()->assertHeader('X-RateLimit-Remaining', '293')->json('data.uuid');
    $areaNote = $area->notes()->where('uuid', $areaNoteUuid)->sole();
    $this->getJson(route('area.notes.index', $area))->assertOk()->assertHeader('X-RateLimit-Remaining', '292');
    $this->getJson(route('area.notes.show', [$area, $areaNote]))->assertOk()->assertHeader('X-RateLimit-Remaining', '291');
    $this->getJson(route('area.notes.tree', $area))->assertOk()->assertHeader('X-RateLimit-Remaining', '290');
    $this->putJson(route('area.notes.update', [$area, $areaNote]), ['content' => 'Saved Area draft'])
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '289');
    $this->post(route('area.notes.media.store', [$area, $areaNote]), [
        'file' => UploadedFile::fake()->create('area.png', 10, 'image/png'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertHeader('X-RateLimit-Remaining', '288');
    $this->deleteJson(route('area.notes.destroy', [$area, $areaNote]))
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '287');
    $this->deleteJson(route('notes.destroy', $note))->assertOk()->assertHeader('X-RateLimit-Remaining', '286');

    $this->assertSoftDeleted('notes', ['id' => $note->id, 'content' => 'Saved personal draft']);
    $this->assertSoftDeleted('notes', ['id' => $areaNote->id, 'content' => 'Saved Area draft']);
    $this->assertDatabaseCount('note_media', 2);
    foreach (NoteMedia::query()->pluck('path') as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('shares the Letters allowance across different letters media and export operations', function (): void {
    $this->freezeTime();
    Storage::fake('public');
    $user = User::factory()->create();
    $otherLetter = Letter::factory()->for($user)->create();
    Queue::fake([GenerateLetterExport::class]);
    Passport::actingAs($user);

    $letterUuid = $this->postJson(route('letters.store'), [
        'title' => 'Personal letter',
        'content' => '{"version":1,"blocks":[{"type":"paragraph","content":"Original words."}]}',
    ])->assertCreated()->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '299')->json('data.uuid');
    $letter = Letter::query()->where('uuid', $letterUuid)->sole();
    $this->getJson(route('letters.show', $otherLetter))->assertOk()->assertHeader('X-RateLimit-Remaining', '298');
    $this->patchJson(route('letters.update', $letter), ['title' => 'Saved personal letter'])
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '297');
    $this->post(route('letters.media.store', $letter), [
        'file' => UploadedFile::fake()->create('letter.png', 10, 'image/png'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertHeader('X-RateLimit-Remaining', '296');
    $exportUuid = $this->postJson(route('letters.exports.store', $letter), ['format' => 'square'])
        ->assertAccepted()->assertHeader('X-RateLimit-Remaining', '295')->json('data.uuid');
    $export = $letter->exports()->where('uuid', $exportUuid)->sole();
    $this->getJson(route('letters.exports.index', $letter))->assertOk()->assertHeader('X-RateLimit-Remaining', '294');
    $this->getJson(route('letters.exports.show', [$letter, $export]))
        ->assertOk()->assertHeader('X-RateLimit-Remaining', '293');
    $readyExport = LetterExport::factory()->for($otherLetter)->create([
        'status' => LetterExportStatus::READY, 'page_count' => 2,
    ]);
    $this->patchJson(route('letters.exports.update', [$otherLetter, $readyExport]), [
        'pages' => [
            ['uuid' => '11111111-1111-4111-8111-111111111111', 'layout' => 'cover', 'title' => 'Updated cover', 'subtitle' => null, 'blocks' => []],
            ['uuid' => '22222222-2222-4222-8222-222222222222', 'layout' => 'body', 'title' => null, 'subtitle' => null, 'blocks' => []],
        ],
    ])->assertOk()->assertHeader('X-RateLimit-Remaining', '292');

    $this->assertDatabaseHas('letters', ['id' => $letter->id, 'title' => 'Saved personal letter']);
    $this->assertDatabaseHas('letter_exports', ['id' => $export->id, 'status' => 'queued', 'format' => 'square']);
    $this->assertSame('Updated cover', $readyExport->fresh()->pages[0]['title']);
    $this->assertDatabaseCount('letter_media', 1);
    Storage::disk('public')->assertExists(LetterMedia::query()->sole()->path);
    Queue::assertPushed(GenerateLetterExport::class, fn (GenerateLetterExport $job): bool => $job->letterExport->is($export));
});
