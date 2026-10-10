<?php

namespace Tests\Feature\RateLimiting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_429_after_thirty_authenticated_requests_with_retry_headers(): void
    {
        $this->travelTo('2026-09-30T00:00:00+00:00');
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '29')
            ->assertHeaderMissing('Retry-After');
        for ($request = 2; $request <= 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }

        $this->getJson(route('auth.me'))->assertTooManyRequests()
            ->assertJsonPath('data', null)
            ->assertJsonPath('status', 429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset', '1790726460');
    }

    public function test_guest_allowance_returns_429_after_thirty_requests_and_renews_after_one_minute(): void
    {
        $this->freezeTime();

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('plan.index'))->assertOk()
                ->assertHeader('X-RateLimit-Limit', '30')
                ->assertHeader('X-RateLimit-Remaining', (string) (30 - $request));
        }
        $this->getJson(route('plan.index'))->assertTooManyRequests();

        $this->travel(61)->seconds();

        $this->getJson(route('plan.index'))->assertOk()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '29');
    }

    public function test_authenticated_account_shares_its_allowance_between_protected_and_public_endpoints(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create());

        for ($request = 1; $request < 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->getJson(route('plan.index'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->getJson(route('auth.me'))->assertTooManyRequests();
    }

    public function test_guest_shares_its_allowance_between_public_endpoints_and_login_validation_failures(): void
    {
        $this->freezeTime();

        for ($request = 1; $request <= 15; $request++) {
            $this->getJson(route('plan.index'))->assertOk();
            $this->postJson(route('auth.login'))->assertUnprocessable()
                ->assertJsonValidationErrors(['username', 'password']);
        }

        $this->getJson(route('plan.index'))->assertTooManyRequests();
        $this->postJson(route('auth.login'))->assertTooManyRequests();
    }

    public function test_accounts_using_the_same_ip_have_separate_allowances(): void
    {
        $this->freezeTime();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        Passport::actingAs($first);

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->getJson(route('auth.me'))->assertTooManyRequests();

        Passport::actingAs($second);

        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '29');
    }

    public function test_account_keeps_its_allowance_when_its_ip_changes(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);

        for ($request = 1; $request < 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])
            ->getJson(route('auth.me'))->assertTooManyRequests();
    }

    public function test_guest_ips_have_separate_allowances(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('plan.index'))->assertOk();
        }
        $this->getJson(route('plan.index'))->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->getJson(route('plan.index'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '29');
    }

    public function test_resource_autosaves_accept_more_than_thirty_updates_without_losing_notes_or_files(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Research']);
        $resource->attachments()->create([
            'kind' => 'file', 'path' => 'resources/reference.txt', 'original_name' => 'reference.txt',
        ]);
        Storage::disk('local')->put('resources/reference.txt', 'Reference content');
        Passport::actingAs($user);
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => 'Saved notes']]];

        for ($request = 1; $request <= 31; $request++) {
            $this->patchJson(route('resource.update', $resource), [
                'title' => 'Revision '.$request,
                'content' => $content,
            ])->assertOk()->assertHeader('X-RateLimit-Limit', '120');
        }

        $this->assertDatabaseHas('resources', ['id' => $resource->id, 'title' => 'Revision 31']);
        $this->assertSame($content, $resource->fresh()->content);
        $this->assertDatabaseCount('resource_attachments', 1);
        Storage::disk('local')->assertExists('resources/reference.txt');
    }

    public function test_resource_allowance_returns_429_without_mutating_data_and_renews_after_one_minute(): void
    {
        $this->travelTo('2026-09-30T00:00:00+00:00');
        Storage::fake('local');
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Original title']);
        Passport::actingAs($user);

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson(route('resource.tags'))->assertOk()
                ->assertHeader('X-RateLimit-Limit', '120')
                ->assertHeader('X-RateLimit-Remaining', (string) (120 - $request));
        }

        $this->patchJson(route('resource.update', $resource), ['title' => 'Blocked title'])
            ->assertTooManyRequests()
            ->assertJsonPath('data', null)
            ->assertJsonPath('status', 429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Limit', '120')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset', '1790726460');
        $this->post(route('resource.attachments.store', $resource), [
            'files' => [UploadedFile::fake()->createWithContent('blocked.txt', 'Blocked file')],
        ], ['Accept' => 'application/json'])->assertTooManyRequests();
        $this->deleteJson(route('resource.destroy', $resource))->assertTooManyRequests();

        $this->assertDatabaseHas('resources', ['id' => $resource->id, 'title' => 'Original title', 'deleted_at' => null]);
        $this->assertDatabaseEmpty('resource_attachments');
        $this->assertDatabaseEmpty('trash_entries');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '29');

        $this->travel(61)->seconds();

        $this->patchJson(route('resource.update', $resource), ['title' => 'Saved after renewal'])
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
        $this->assertDatabaseHas('resources', ['id' => $resource->id, 'title' => 'Saved after renewal']);
    }

    public function test_resource_saves_remain_available_after_the_general_api_allowance_is_exhausted(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $resource = $user->resources()->create(['title' => 'Research']);
        Passport::actingAs($user);

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->getJson(route('auth.me'))->assertTooManyRequests();

        $this->patchJson(route('resource.update', $resource), ['title' => 'Independent autosave'])
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '119');

        $this->assertDatabaseHas('resources', ['id' => $resource->id, 'title' => 'Independent autosave']);
    }

    public function test_all_resource_actions_share_the_same_allowance(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('resource.index'))->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
        $uuid = $this->postJson(route('resource.store'), ['title' => 'Research'])
            ->assertCreated()->assertHeader('X-RateLimit-Remaining', '118')->json('data.uuid');
        $this->getJson(route('resource.tags'))->assertOk()->assertHeader('X-RateLimit-Remaining', '117');
        $this->getJson(route('resource.show', $uuid))->assertOk()->assertHeader('X-RateLimit-Remaining', '116');
        $this->patchJson(route('resource.update', $uuid), ['title' => 'Updated research'])
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '115');
        $attachment = $this->post(route('resource.attachments.store', $uuid), [
            'files' => [UploadedFile::fake()->createWithContent('reference.txt', 'Reference content')],
        ], ['Accept' => 'application/json'])->assertOk()->assertHeader('X-RateLimit-Remaining', '114')
            ->json('data.attachments.0.uuid');
        $this->get(route('resource.attachments.show', [$uuid, $attachment]))
            ->assertOk()->assertDownload('reference.txt')->assertHeader('X-RateLimit-Remaining', '113');
        $this->deleteJson(route('resource.attachments.destroy', [$uuid, $attachment]))
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '112');
        $this->postJson(route('resource.archive', $uuid))->assertOk()->assertHeader('X-RateLimit-Remaining', '111');
        $this->postJson(route('resource.restore', $uuid))->assertOk()->assertHeader('X-RateLimit-Remaining', '110');
        $this->deleteJson(route('resource.destroy', $uuid))->assertOk()->assertHeader('X-RateLimit-Remaining', '109');

        $this->assertSoftDeleted('resources', ['uuid' => $uuid, 'title' => 'Updated research']);
        $this->assertSoftDeleted('resource_attachments', ['uuid' => $attachment]);
    }

    public function test_resource_allowance_follows_the_account_across_records_and_ip_addresses(): void
    {
        $this->freezeTime();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $resources = [
            $first->resources()->create(['title' => 'First resource']),
            $first->resources()->create(['title' => 'Second resource']),
        ];
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        Passport::actingAs($first);

        for ($request = 1; $request <= 118; $request++) {
            $this->getJson(route('resource.tags'))->assertOk();
        }
        $this->getJson(route('resource.show', $resources[0]))->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
        $this->getJson(route('resource.show', $resources[1]))->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $this->getJson(route('resource.tags'))->assertTooManyRequests();

        Passport::actingAs($second);

        $this->getJson(route('resource.tags'))->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
    }
}
