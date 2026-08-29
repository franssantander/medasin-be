<?php

namespace Tests\Feature\Area;

use App\Models\NoteMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AreaNoteEditorTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_note_media_is_validated_stored_and_deleted_with_its_note_tree(): void
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
        $this->assertDatabaseCount('note_media', 0);
        $paths->each(fn (string $path) => Storage::disk('public')->assertMissing($path));
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
}
