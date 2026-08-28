<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class AreaSeeder extends Seeder
{
    /**
     * Seed the demo user's canonical Areas.
     */
    public function run(User $user): void
    {
        $areas = [
            [
                'name' => 'Career',
                'icon' => 'BriefcaseBusiness',
                'description' => 'Professional growth, meaningful work, and long-term career direction.',
            ],
            [
                'name' => 'Health',
                'icon' => 'HeartPulse',
                'description' => 'Physical health, energy, fitness, and sustainable daily routines.',
            ],
            [
                'name' => 'Personal Development',
                'icon' => 'Sprout',
                'description' => 'Learning, reflection, creativity, and becoming more intentional.',
            ],
            [
                'name' => 'Work',
                'icon' => 'Laptop',
                'description' => 'Focused execution, responsibilities, and the craft of doing excellent work.',
            ],
            [
                'name' => 'Spirit',
                'icon' => 'Sparkles',
                'description' => 'Inner clarity, meaning, stillness, and connection to something greater.',
            ],
            [
                'name' => 'Business',
                'icon' => 'ChartNoAxesCombined',
                'description' => 'Strategy, enterprise, leadership, and building durable value.',
            ],
            [
                'name' => 'Finances',
                'icon' => 'WalletCards',
                'description' => 'Financial stewardship, stability, and thoughtful long-term decisions.',
            ],
        ];

        foreach ($areas as $area) {
            $slug = str($area['name'])->slug()->toString();
            $imagePath = "areas/backgrounds/seed/{$slug}.png";
            $sourcePath = database_path("seeders/assets/areas/{$slug}.png");

            Storage::disk('public')->put($imagePath, file_get_contents($sourcePath));

            $user->areas()->updateOrCreate(
                ['slug' => $slug],
                [
                    ...$area,
                    'background' => '#000000',
                    'background_image' => $imagePath,
                    'archived_at' => null,
                ],
            );
        }
    }
}
