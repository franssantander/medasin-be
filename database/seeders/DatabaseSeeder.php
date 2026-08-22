<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\PassportServiceProvider;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient(
            name: 'Medasin Personal Access Client',
            provider: 'users',
        );

        User::factory(10)->create();

        User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);

        $this->call([
            PlanSeeder::class,
        ]);
    }
}