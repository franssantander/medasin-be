<?php

namespace Tests\Feature\Area;

use App\Models\User;
use Database\Seeders\AreaSeeder;
use Database\Seeders\DatabaseSeeder;
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
            'spiritual' => 'Sparkles',
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

    public function test_database_seeder_creates_habits_with_icons_and_normalized_schedules(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $habits = $user->areas()->with('habits')->get()->flatMap->habits->keyBy('name');

        $this->assertSame('Footprints', $habits['Morning walk']->icon);
        $this->assertNull($habits['Morning walk']->schedule);
        $this->assertSame('Dumbbell', $habits['Strength training']->icon);
        $this->assertSame(['monday', 'wednesday', 'friday'], $habits['Strength training']->schedule['days']);
        $this->assertSame('ListChecks', $habits['Weekly review']->icon);
        $this->assertSame(['friday'], $habits['Weekly review']->schedule['days']);
        $this->assertSame('BookOpen', $habits['Read for thirty minutes']->icon);
        $this->assertNull($habits['Read for thirty minutes']->schedule);
        $this->assertSame('NotebookPen', $habits['Monthly reflection']->icon);
        $this->assertSame([28], $habits['Monthly reflection']->schedule['dates']);
    }
}
