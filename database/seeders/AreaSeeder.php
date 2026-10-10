<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Profile\FileCleanupService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

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
                'name' => 'Spiritual',
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

        DB::transaction(function () use ($user, $areas): void {
            foreach ($areas as $area) {
                $slug = str($area['name'])->slug()->toString();
                $imagePath = "areas/backgrounds/seed/{$user->uuid}/{$slug}.png";
                if (! Storage::disk('public')->exists($imagePath)) {
                    $contents = file_get_contents(database_path("seeders/assets/areas/{$slug}.png"));
                    if ($contents === false) {
                        throw new RuntimeException('Unable to read demo Area image.');
                    }

                    DB::afterRollBack(function () use ($imagePath): void {
                        try {
                            app(FileCleanupService::class)->deleteOrQueue(['public' => [$imagePath]]);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    });
                    if (! Storage::disk('public')->put($imagePath, $contents)) {
                        throw new RuntimeException('Unable to store demo Area image.');
                    }
                }

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
        });
    }
}
