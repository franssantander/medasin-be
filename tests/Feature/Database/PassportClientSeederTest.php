<?php

namespace Tests\Feature\Database;

use Database\Seeders\PassportClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PassportClientSeederTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('compatibleProviders')]
    public function test_seeding_reuses_an_active_compatible_client_without_changing_credentials(?string $provider): void
    {
        $client = Client::factory()->asPersonalAccessTokenClient()->create(['provider' => $provider]);
        $secret = $client->getRawOriginal('secret');

        $this->seed(PassportClientSeeder::class);
        $this->seed(PassportClientSeeder::class);

        $this->assertDatabaseCount('oauth_clients', 1);
        $this->assertSame($client->getKey(), app(ClientRepository::class)->personalAccessClient('users')->getKey());
        $this->assertSame($secret, $client->fresh()->getRawOriginal('secret'));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function compatibleProviders(): array
    {
        return [
            'explicit users provider' => ['users'],
            'default users provider' => [null],
        ];
    }

    #[DataProvider('incompatibleClients')]
    public function test_seeding_creates_a_client_when_existing_clients_are_incompatible(array $attributes): void
    {
        $existingClient = Client::factory()->asPersonalAccessTokenClient()->create($attributes);

        $this->seed(PassportClientSeeder::class);
        $this->seed(PassportClientSeeder::class);

        $this->assertDatabaseCount('oauth_clients', 2);
        $this->assertModelExists($existingClient);
        $client = app(ClientRepository::class)->personalAccessClient('users');
        $this->assertNotSame($existingClient->getKey(), $client->getKey());
        $this->assertSame('users', $client->provider);
        $this->assertFalse($client->revoked);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incompatibleClients(): array
    {
        return [
            'revoked client' => [['provider' => 'users', 'revoked' => true]],
            'different provider' => [['provider' => 'admins']],
            'different grant' => [['provider' => 'users', 'grant_types' => ['client_credentials']]],
        ];
    }

    public function test_seeding_does_not_reuse_an_implicit_client_for_a_different_default_provider(): void
    {
        config(['auth.guards.api.provider' => 'admins']);
        $existingClient = Client::factory()->asPersonalAccessTokenClient()->create();

        $this->seed(PassportClientSeeder::class);

        $this->assertDatabaseCount('oauth_clients', 2);
        $this->assertNotSame($existingClient->getKey(), app(ClientRepository::class)->personalAccessClient('users')->getKey());
    }
}
