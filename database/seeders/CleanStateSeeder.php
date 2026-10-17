<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CleanStateSeeder extends Seeder
{
    /**
     * Seed the reference data every environment needs, without any user accounts or content.
     */
    public function run(): void
    {
        $this->call([
            PassportClientSeeder::class,
            PlanSeeder::class,
        ]);
    }
}
