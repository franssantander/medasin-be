<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('moves a note subtree while preserving its descendants', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $firstUuid = $this->postJson(route('notes.store'), ['title' => 'First root', 'content' => 'Root'])
        ->assertCreated()->json('data.uuid');
    $secondUuid = $this->postJson(route('notes.store'), ['title' => 'Second root', 'content' => 'Root', 'is_pinned' => true])
        ->assertCreated()->json('data.uuid');
    $childUuid = $this->postJson(route('notes.store'), [
        'title' => 'Child', 'content' => 'Child content', 'parent_uuid' => $firstUuid,
    ])->assertCreated()->json('data.uuid');
    $grandchildUuid = $this->postJson(route('notes.store'), [
        'title' => 'Grandchild', 'content' => 'Grandchild content', 'parent_uuid' => $childUuid,
    ])->assertCreated()->json('data.uuid');

    $this->patchJson(route('notes.update', $childUuid), ['parent_uuid' => $secondUuid])->assertOk();
    $this->getJson(route('notes.tree'))->assertOk()
        ->assertJsonPath('data.0.uuid', $secondUuid)
        ->assertJsonPath('data.0.children.0.uuid', $childUuid)
        ->assertJsonPath('data.0.children.0.children.0.uuid', $grandchildUuid)
        ->assertJsonPath('data.1.children', []);

    $second = $user->standaloneNotes()->where('uuid', $secondUuid)->sole();
    $child = $user->standaloneNotes()->where('uuid', $childUuid)->sole();
    $this->assertDatabaseHas('notes', ['uuid' => $childUuid, 'parent_id' => $second->id]);
    $this->assertDatabaseHas('notes', ['uuid' => $grandchildUuid, 'parent_id' => $child->id]);
});

it('returns 422 and preserves the tree when moving a root beneath its descendant', function (): void {
    $user = User::factory()->create();
    $root = $user->standaloneNotes()->create(['title' => 'Root', 'content' => 'Root']);
    $child = $user->standaloneNotes()->create(['title' => 'Child', 'content' => 'Child', 'parent_id' => $root->id]);
    Passport::actingAs($user);

    $this->patchJson(route('notes.update', $root), ['parent_uuid' => $child->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');

    $this->assertDatabaseHas('notes', ['id' => $root->id, 'parent_id' => null]);
    $this->assertDatabaseHas('notes', ['id' => $child->id, 'parent_id' => $root->id]);
});
