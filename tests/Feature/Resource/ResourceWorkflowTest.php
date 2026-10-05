<?php

use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('restores a trashed attachment with its original download content', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $uuid = $this->postJson(route('resource.store'), [
        'title' => 'Research',
        'files' => [UploadedFile::fake()->createWithContent('reference.txt', 'Saved reference content')],
        'tag_names' => ['Research'],
    ])->assertCreated()->json('data.uuid');
    $resource = $user->resources()->where('uuid', $uuid)->sole();
    $attachment = $resource->attachments()->sole();
    Storage::disk('local')->assertExists($attachment->path);

    $this->deleteJson(route('resource.attachments.destroy', [$resource, $attachment->uuid]))->assertOk();
    $this->getJson(route('resource.attachments.show', [$resource, $attachment->uuid]))->assertNotFound();
    $entry = TrashEntry::where('subject_uuid', $attachment->uuid)->sole();
    $this->postJson(route('trash.restore', $entry))->assertOk();
    $this->getJson(route('resource.attachments.show', [$resource, $attachment->uuid]))
        ->assertOk()->assertDownload('reference.txt')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson(route('resource.show', $resource))->assertOk()
        ->assertJsonPath('data.attachments.0.uuid', $attachment->uuid);

    Storage::disk('local')->assertExists($attachment->path);
    expect(Storage::disk('local')->get($attachment->path))->toBe('Saved reference content');
    $this->assertDatabaseHas('resource_attachments', ['id' => $attachment->id, 'deleted_at' => null]);
    $this->assertModelMissing($entry);
});
