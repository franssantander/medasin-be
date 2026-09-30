<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class PassportClientSeeder extends Seeder
{
    /**
     * Seed the application's Passport client.
     */
    public function run(): void
    {
        $personalAccessClientExists = Passport::client()->newQuery()
            ->where('revoked', false)
            ->where(function (Builder $query): void {
                $query->where('provider', 'users');

                if (config('auth.guards.api.provider') === 'users') {
                    $query->orWhereNull('provider');
                }
            })
            ->get()
            ->contains(fn (Client $client): bool => $client->hasGrantType('personal_access'));

        if ($personalAccessClientExists) {
            return;
        }

        app(ClientRepository::class)->createPersonalAccessGrantClient(
            name: 'Medasin Personal Access Client',
            provider: 'users',
        );
    }
}
