<?php

namespace Tests\Feature\Resource;

use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_resources_owned_by_the_authenticated_user_are_returned(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $active = new Resource(['title' => 'Active resource']);
        $user->resources()->save($active);

        $archived = new Resource(['title' => 'Archived resource', 'archived_at' => now()]);
        $user->resources()->save($archived);

        $foreign = new Resource(['title' => 'Foreign resource']);
        $otherUser->resources()->save($foreign);

        $this->actingAs($user, 'api')
            ->getJson(route('resource.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $active->uuid);
    }

    public function test_resource_index_requires_authentication(): void
    {
        $this->getJson(route('resource.index'))->assertUnauthorized();
    }
}
