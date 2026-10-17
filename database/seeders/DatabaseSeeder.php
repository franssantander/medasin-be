<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CleanStateSeeder::class);

        // Demo accounts have published passwords, so production only gets the clean state.
        if (! app()->isProduction()) {
            $this->call(DemoUserSeeder::class);
        }
    }
}
