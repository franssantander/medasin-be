<?php

namespace Tests\Feature\Note;

use App\Models\NoteMedia;
use App\Models\TrashEntry;
use App\Models\User;
use App\Services\Note\NoteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StandaloneNoteTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('partialWriteFailures')]
    public function test_note_media_storage_failure_removes_a_file_written_before_the_failure(bool $throw): void
    {
        $disk = Storage::fake('public');
        $user = User::factory()->create();
        $note = $user->standaloneNotes()->create(['title' => 'Partial upload', 'content' => 'Note content']);
        Passport::actingAs($user);
        $this->failAfterStorageWrite('public', $disk, $throw);

        try {
            $this->postJson(route('notes.media.store', $note->uuid), [
                'file' => UploadedFile::fake()->create('photo.png', 120, 'image/png'),
            ])->assertServerError();
        } finally {
            Storage::set('public', $disk);
        }

        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseCount('note_media', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertModelExists($note);
    }

    public static function partialWriteFailures(): array
    {
        return [
            'storage returns false after writing' => [false],
            'storage throws after writing' => [true],
        ];
    }

    public function test_note_media_does_not_write_a_file_after_its_cached_owner_was_deleted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $note = $user->standaloneNotes()->create(['title' => 'Deleted owner note', 'content' => 'Note content']);
        $user->delete();

        try {
            app(NoteService::class)->storeMedia($user, $note, UploadedFile::fake()->create('photo.png', 120, 'image/png'));
            $this->fail('An upload for a deleted owner must fail.');
        } catch (ModelNotFoundException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('note_media', 0);
        }
    }

    public function test_note_media_does_not_write_a_file_after_its_cached_note_was_deleted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $note = $user->standaloneNotes()->create(['title' => 'Deleted note', 'content' => 'Note content']);
        $note->forceDelete();

        try {
            app(NoteService::class)->storeMedia($user, $note, UploadedFile::fake()->create('photo.png', 120, 'image/png'));
            $this->fail('An upload for a deleted note must fail.');
        } catch (ModelNotFoundException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('note_media', 0);
        }
    }

    public function test_note_media_persistence_failure_removes_the_new_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $note = $user->standaloneNotes()->create(['title' => 'Rollback note', 'content' => 'Note content']);
        Passport::actingAs($user);
        NoteMedia::creating(function (): void {
            throw new RuntimeException('Simulated note media persistence failure.');
        });

        try {
            $this->postJson(route('notes.media.store', $note->uuid), [
                'file' => UploadedFile::fake()->create('photo.png', 120, 'image/png'),
            ])->assertServerError();
        } finally {
            NoteMedia::flushEventListeners();
            NoteMedia::clearBootedModels();
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('note_media', 0);
        $this->assertModelExists($note);
    }

    public function test_a_later_outer_transaction_rollback_removes_uploaded_note_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $note = $user->standaloneNotes()->create(['title' => 'Outer rollback note', 'content' => 'Note content']);

        try {
            DB::transaction(function () use ($user, $note): void {
                app(NoteService::class)->storeMedia($user, $note, UploadedFile::fake()->create('photo.png', 120, 'image/png'));
                $this->assertCount(1, Storage::disk('public')->allFiles());
                throw new RuntimeException('Roll back the enclosing transaction.');
            });
            $this->fail('The enclosing transaction must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Roll back the enclosing transaction.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('note_media', 0);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(route('notes.index'))->assertUnauthorized();
    }

    public function test_standalone_notes_support_crud_pagination_and_nested_trees(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing']);
        $areaNote = $area->notes()->create(['title' => 'Area note', 'content' => 'Area content']);
        Passport::actingAs($user);

        $root = $this->postJson(route('notes.store'), [
            'title' => 'Root',
            'content' => 'Root content',
            'is_pinned' => true,
        ])->assertCreated()->assertJsonPath('data.area_id', null)->json('data');
        $child = $this->postJson(route('notes.store'), [
            'title' => 'Child',
            'content' => 'Child content',
            'parent_uuid' => $root['uuid'],
        ])->assertCreated()->assertJsonPath('data.parent_uuid', $root['uuid'])->json('data');

        $this->putJson(route('notes.update', $root['uuid']), [
            'title' => 'Renamed root',
            'content' => 'Updated content',
        ])->assertOk()->assertJsonPath('data.title', 'Renamed root');

        $this->getJson(route('notes.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.uuid', $root['uuid']);
        $this->getJson(route('notes.show', $child['uuid']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Child');
        $this->getJson(route('notes.tree'))
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $root['uuid'])
            ->assertJsonPath('data.0.children.0.uuid', $child['uuid']);
        $this->deleteJson(route('notes.destroy', $child['uuid']))->assertOk();

        $this->assertDatabaseHas('notes', [
            'uuid' => $root['uuid'],
            'user_id' => $user->getKey(),
            'area_id' => null,
            'title' => 'Renamed root',
        ]);
        $this->assertDatabaseHas('notes', [
            'id' => $areaNote->getKey(),
            'user_id' => $user->getKey(),
            'area_id' => $area->getKey(),
        ]);
        $this->assertSoftDeleted('notes', ['uuid' => $child['uuid']]);
    }

    public function test_standalone_notes_reject_cross_collection_parents_and_other_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing']);
        $areaNote = $area->notes()->create(['title' => 'Area note', 'content' => 'Area content']);
        Passport::actingAs($other);
        $otherNote = $this->postJson(route('notes.store'), [
            'title' => 'Private',
            'content' => 'Private content',
        ])->assertCreated()->json('data');

        Passport::actingAs($user);
        $root = $this->postJson(route('notes.store'), [
            'title' => 'Root',
            'content' => 'Root content',
        ])->assertCreated()->json('data');
        $child = $this->postJson(route('notes.store'), [
            'title' => 'Child',
            'content' => 'Child content',
            'parent_uuid' => $root['uuid'],
        ])->assertCreated()->json('data');

        $this->postJson(route('notes.store'), [
            'title' => 'Invalid parent',
            'content' => 'Invalid content',
            'parent_uuid' => $otherNote['uuid'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->postJson(route('notes.store'), [
            'title' => 'Area parent',
            'content' => 'Invalid content',
            'parent_uuid' => $areaNote->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->putJson(route('notes.update', $root['uuid']), [
            'parent_uuid' => $child['uuid'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');

        Passport::actingAs($other);
        $this->getJson(route('notes.show', $root['uuid']))->assertNotFound();
        $this->putJson(route('notes.update', $root['uuid']), ['title' => 'No access'])->assertNotFound();
        $this->deleteJson(route('notes.destroy', $root['uuid']))->assertNotFound();
        $this->getJson(route('notes.index'))->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_standalone_note_media_is_validated_and_retained_through_trash_restore(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Passport::actingAs($user);
        $root = $this->postJson(route('notes.store'), [
            'title' => 'Draft',
            'content' => 'Draft content',
        ])->assertCreated()->json('data');
        $child = $this->postJson(route('notes.store'), [
            'title' => 'Child',
            'content' => 'Child content',
            'parent_uuid' => $root['uuid'],
        ])->assertCreated()->json('data');

        $this->post(route('notes.media.store', $root['uuid']), [
            'file' => UploadedFile::fake()->create('diagram.png', 120, 'image/png'),
        ])->assertCreated();
        $path = NoteMedia::query()->sole()->path;
        Storage::disk('public')->assertExists($path);

        $this->post(route('notes.media.store', $root['uuid']), [
            'file' => UploadedFile::fake()->create('oversized.png', 10 * 1024 + 1, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->deleteJson(route('notes.destroy', $root['uuid']))
            ->assertOk()
            ->assertJsonPath('message', 'Note and its subpages moved to Trash. They will be permanently deleted after 30 days.');

        $entry = TrashEntry::query()->where('item_type', 'note')->sole();
        $this->getJson(route('trash.index'))
            ->assertOk()
            ->assertJsonPath('data.data.0.context', 'Notes')
            ->assertJsonPath('data.data.0.group_size', 2);
        $this->postJson(route('trash.restore', $entry->uuid))->assertOk();

        $this->assertNotSoftDeleted('notes', ['uuid' => $root['uuid']]);
        $this->assertNotSoftDeleted('notes', ['uuid' => $child['uuid']]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_standalone_note_creation_requires_title_and_content(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->postJson(route('notes.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'content']);
    }

    private function failAfterStorageWrite(string $diskName, FilesystemAdapter $disk, bool $throw): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('putFileAs')->once()->andReturnUsing(
            function (string $directory, UploadedFile $file, string $name) use ($disk, $throw): false {
                $path = "{$directory}/{$name}";
                $disk->put($path, 'File bytes written before storage reported failure.');
                $disk->assertExists($path);

                if ($throw) {
                    throw new RuntimeException('Simulated storage failure after writing the file.');
                }

                return false;
            },
        );
        $mock->shouldReceive('delete')->once()->andReturnUsing(fn (string $path): bool => $disk->delete($path));
        Storage::set($diskName, $mock);
    }
}
