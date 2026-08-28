<?php

namespace Tests\Feature\Area;

use App\Models\User;
use Database\Seeders\AreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AreaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_canonical_areas_with_matching_icons_and_images_idempotently(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $seeder = $this->app->make(AreaSeeder::class);

        $seeder->run($user);
        $seeder->run($user);

        $expectedAreas = [
            'business' => 'ChartNoAxesCombined',
            'career' => 'BriefcaseBusiness',
            'finances' => 'WalletCards',
            'health' => 'HeartPulse',
            'personal-development' => 'Sprout',
            'spirit' => 'Sparkles',
            'work' => 'Laptop',
        ];

        $areas = $user->areas()->orderBy('slug')->get();

        $this->assertCount(7, $areas);

        foreach ($expectedAreas as $slug => $icon) {
            $area = $areas->firstWhere('slug', $slug);

            $this->assertNotNull($area);
            $this->assertSame($icon, $area->icon);
            $this->assertSame('#000000', $area->background);
            $this->assertNull($area->archived_at);
            $this->assertSame("areas/backgrounds/seed/{$slug}.png", $area->background_image);
            Storage::disk('public')->assertExists($area->background_image);
        }
    }
}
