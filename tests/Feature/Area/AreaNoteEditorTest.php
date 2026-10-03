<?php

namespace Tests\Feature\Area;

use App\Models\NoteMedia;
use App\Models\User;
use App\Services\Note\NoteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AreaNoteEditorTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('partialWriteFailures')]
    public function test_area_note_storage_failure_removes_a_file_written_before_the_failure(bool $throw): void
    {
        $disk = Storage::fake('public');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Partial upload area']);
        $note = $area->notes()->create(['title' => 'Partial upload', 'content' => 'Note content']);
        Passport::actingAs($user);
        $this->failAfterStorageWrite('public', $disk, $throw);

        try {
            $this->postJson(route('area.notes.media.store', [$area, $note]), [
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

    public function test_area_note_upload_does_not_write_after_its_cached_owner_was_deleted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Deleted owner area']);
        $note = $area->notes()->create(['title' => 'Deleted owner note', 'content' => 'Note content']);
        $user->delete();

        try {
            app(NoteService::class)->storeMedia($user, $note, UploadedFile::fake()->create('photo.png', 120, 'image/png'), $area);
            $this->fail('An upload for a deleted area owner must fail.');
        } catch (ModelNotFoundException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('note_media', 0);
        }
    }

    public function test_area_note_upload_refreshes_the_area_before_writing_a_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Archived area']);
        $note = $area->notes()->create(['title' => 'Archived note', 'content' => 'Note content']);
        $user->areas()->whereKey($area->getKey())->update(['archived_at' => now()]);

        try {
            app(NoteService::class)->storeMedia($user, $note, UploadedFile::fake()->create('photo.png', 120, 'image/png'), $area);
            $this->fail('An upload for an archived area must fail.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('note_media', 0);
    }

    public function test_area_note_media_persistence_failure_removes_the_new_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Rollback area']);
        $note = $area->notes()->create(['title' => 'Rollback note', 'content' => 'Note content']);
        Passport::actingAs($user);
        NoteMedia::creating(function (): void {
            throw new RuntimeException('Simulated area note media persistence failure.');
        });

        try {
            $this->postJson(route('area.notes.media.store', [$area, $note]), [
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

    public function test_notes_can_be_nested_and_returned_as_a_tree(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing']);
        Passport::actingAs($user);

        $root = $this->postJson(route('area.notes.store', $area), [
            'title' => 'Root',
            'content' => $this->document('Root content'),
            'is_pinned' => true,
        ])->assertCreated()->json('data');
        $child = $this->postJson(route('area.notes.store', $area), [
            'title' => 'Child',
            'content' => $this->document('Child content'),
            'parent_uuid' => $root['uuid'],
        ])->assertCreated()->assertJsonPath('data.parent_uuid', $root['uuid'])->json('data');
        $grandchild = $this->postJson(route('area.notes.store', $area), [
            'title' => 'Grandchild',
            'content' => $this->document('Grandchild content'),
            'parent_uuid' => $child['uuid'],
        ])->assertCreated()->json('data');

        $this->getJson(route('area.notes.tree', $area))
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $root['uuid'])
            ->assertJsonPath('data.0.content', $this->document('Root content'))
            ->assertJsonPath('data.0.children.0.uuid', $child['uuid'])
            ->assertJsonPath('data.0.children.0.content', $this->document('Child content'))
            ->assertJsonPath('data.0.children.0.children.0.uuid', $grandchild['uuid'])
            ->assertJsonPath(
                'data.0.children.0.children.0.content',
                $this->document('Grandchild content'),
            );
    }

    public function test_note_parenting_rejects_cycles_and_notes_from_other_areas(): void
    {
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing']);
        $otherArea = $user->areas()->create(['name' => 'Reading']);
        $root = $area->notes()->create(['title' => 'Root', 'content' => $this->document()]);
        $child = $area->notes()->create([
            'parent_id' => $root->getKey(),
            'title' => 'Child',
            'content' => $this->document(),
        ]);
        $other = $otherArea->notes()->create(['title' => 'Other', 'content' => $this->document()]);
        Passport::actingAs($user);

        $this->putJson(route('area.notes.update', [$area, $root]), [
            'parent_uuid' => $child->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');

        $this->putJson(route('area.notes.update', [$area, $root]), [
            'parent_uuid' => $other->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
    }

    public function test_note_media_is_validated_and_retained_while_its_note_tree_is_in_trash(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing']);
        $note = $area->notes()->create(['title' => 'Draft', 'content' => $this->document()]);
        $child = $area->notes()->create([
            'parent_id' => $note->getKey(),
            'title' => 'Child',
            'content' => $this->document(),
        ]);
        Passport::actingAs($user);

        $this->post(route('area.notes.media.store', [$area, $note]), [
            'file' => UploadedFile::fake()->create('diagram.png', 120, 'image/png'),
        ])->assertCreated()->assertJsonPath('data.kind', 'image');
        $this->post(route('area.notes.media.store', [$area, $child]), [
            'file' => UploadedFile::fake()->create('clip.mp4', 120, 'video/mp4'),
        ])->assertCreated()->assertJsonPath('data.kind', 'video');
        $this->post(route('area.notes.media.store', [$area, $note]), [
            'file' => UploadedFile::fake()->create('oversized.png', 10 * 1024 + 1, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $paths = NoteMedia::query()->pluck('path');
        $paths->each(fn (string $path) => Storage::disk('public')->assertExists($path));

        $this->deleteJson(route('area.notes.destroy', [$area, $note]))->assertOk();

        $this->assertSoftDeleted('notes', ['id' => $note->getKey()]);
        $this->assertSoftDeleted('notes', ['id' => $child->getKey()]);
        $this->assertDatabaseCount('note_media', 2);
        $this->assertDatabaseHas('trash_entries', ['subject_uuid' => $note->uuid, 'item_type' => 'note']);
        $paths->each(fn (string $path) => Storage::disk('public')->assertExists($path));
    }

    public function test_archived_areas_reject_note_media_uploads(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $area = $user->areas()->create(['name' => 'Writing', 'archived_at' => now()]);
        $note = $area->notes()->create(['title' => 'Draft', 'content' => $this->document()]);
        Passport::actingAs($user);

        $this->post(route('area.notes.media.store', [$area, $note]), [
            'file' => UploadedFile::fake()->create('diagram.png', 120, 'image/png'),
        ])->assertConflict();
    }

    private function document(string $text = ''): string
    {
        return json_encode([
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'content' => $text,
            ]],
        ], JSON_THROW_ON_ERROR);
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
