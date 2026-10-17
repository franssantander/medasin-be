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
        $this->call(PassportClientSeeder::class);

        // Demo accounts have published passwords, so production only gets the plan catalog.
        $this->call(app()->isProduction() ? PlanSeeder::class : DemoUserSeeder::class);
    }
}
