<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

class PassportClientSeeder extends Seeder
{
    /**
     * Seed the application's Passport client.
     */
    public function run(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient(
            name: 'Medasin Personal Access Client',
            provider: 'users',
        );
    }
}
