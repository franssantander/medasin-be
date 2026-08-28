<?php

namespace Database\Seeders;

use App\Enum\GoalStatus;
use App\Enum\HabitFrequency;
use App\Models\Area;
use App\Models\Project;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

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

        $testUser = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);

        $this->seedAreaInterconnections($testUser);

        $this->call([
            PlanSeeder::class,
        ]);
    }

    private function seedAreaInterconnections(User $user): void
    {
        $health = $this->area($user, 'Health', [
            'icon' => 'heart-pulse',
            'background' => '#DCFCE7',
            'description' => 'Physical health, energy, fitness, and sustainable daily routines.',
        ]);

        $career = $this->area($user, 'Career', [
            'icon' => 'briefcase-business',
            'background' => '#DBEAFE',
            'description' => 'Professional growth, meaningful work, and long-term career direction.',
        ]);

        $personalGrowth = $this->area($user, 'Personal Growth', [
            'icon' => 'sprout',
            'background' => '#F3E8FF',
            'description' => 'Learning, reflection, creativity, and becoming more intentional.',
        ]);

        $travel = $this->area($user, 'Travel', [
            'icon' => 'plane',
            'background' => '#FFEDD5',
            'description' => 'Past travel plans and experiences kept for future reference.',
            'archived_at' => now()->subDays(30),
        ]);

        $this->seedGoals($health, [
            [
                'title' => 'Run a comfortable 10K',
                'description' => 'Build endurance gradually while staying injury-free.',
                'status' => GoalStatus::IN_PROGRESS,
                'start_date' => today()->subWeeks(4),
                'due_date' => today()->addMonths(2),
            ],
            [
                'title' => 'Complete annual health screening',
                'description' => 'Schedule and complete the recommended annual checkup.',
                'status' => GoalStatus::COMPLETED,
                'start_date' => today()->subMonths(2),
                'due_date' => today()->subMonth(),
                'completed_at' => now()->subMonth(),
            ],
        ]);

        $this->seedGoals($career, [
            [
                'title' => 'Lead the next product release',
                'description' => 'Coordinate delivery, documentation, and the release retrospective.',
                'status' => GoalStatus::IN_PROGRESS,
                'start_date' => today()->subWeeks(2),
                'due_date' => today()->addMonths(3),
            ],
            [
                'title' => 'Refresh professional portfolio',
                'description' => 'Document recent projects and measurable outcomes.',
                'status' => GoalStatus::PENDING,
                'due_date' => today()->addMonth(),
            ],
        ]);

        $this->seedGoals($personalGrowth, [
            [
                'title' => 'Read twelve books this year',
                'description' => 'Alternate between practical nonfiction and literature.',
                'status' => GoalStatus::IN_PROGRESS,
                'start_date' => today()->startOfYear(),
                'due_date' => today()->endOfYear(),
            ],
        ]);

        $this->seedGoals($travel, [
            [
                'title' => 'Plan a Japan trip',
                'description' => 'An archived idea retained as a future reference.',
                'status' => GoalStatus::CANCELLED,
            ],
        ]);

        $this->seedHabits($health, [
            [
                'name' => 'Morning walk',
                'description' => 'Walk outside before starting work.',
                'frequency' => HabitFrequency::DAILY,
                'schedule' => ['time' => '07:00'],
                'is_active' => true,
            ],
            [
                'name' => 'Strength training',
                'description' => 'Complete a full-body strength session.',
                'frequency' => HabitFrequency::WEEKLY,
                'schedule' => ['days' => ['monday', 'wednesday', 'friday']],
                'is_active' => true,
            ],
        ]);

        $this->seedHabits($career, [
            [
                'name' => 'Weekly review',
                'description' => 'Review priorities, blockers, and progress every Friday.',
                'frequency' => HabitFrequency::WEEKLY,
                'schedule' => ['days' => ['friday'], 'time' => '16:00'],
                'is_active' => true,
            ],
        ]);

        $this->seedHabits($personalGrowth, [
            [
                'name' => 'Read for thirty minutes',
                'description' => 'Read without notifications or other distractions.',
                'frequency' => HabitFrequency::DAILY,
                'schedule' => ['time' => '21:00'],
                'is_active' => true,
            ],
            [
                'name' => 'Monthly reflection',
                'description' => 'Capture lessons, wins, and adjustments for the next month.',
                'frequency' => HabitFrequency::MONTHLY,
                'schedule' => ['day_of_month' => 28],
                'is_active' => true,
            ],
        ]);

        $this->seedNotes($health, [
            [
                'title' => 'Health priorities',
                'content' => 'Protect sleep, stay consistent, and increase training load gradually.',
                'is_pinned' => true,
            ],
            [
                'title' => 'Meal preparation ideas',
                'content' => 'Prepare simple proteins, vegetables, and grains on Sunday evenings.',
                'is_pinned' => false,
            ],
        ]);

        $this->seedNotes($career, [
            [
                'title' => 'Current career principles',
                'content' => 'Favor high-leverage work, communicate early, and document decisions.',
                'is_pinned' => true,
            ],
        ]);

        $this->seedNotes($personalGrowth, [
            [
                'title' => 'Books to read next',
                'content' => 'Keep the list short and choose the next book before finishing the current one.',
                'is_pinned' => false,
            ],
        ]);

        $fitnessProject = $this->project($user, '10K Training Plan', $health, [
            'description' => 'An eight-week progressive running plan.',
            'icon' => 'footprints',
            'background' => '#DCFCE7',
            'status' => 'active',
            'start_date' => today()->subWeeks(2),
            'due_date' => today()->addWeeks(6),
        ]);

        $portfolioProject = $this->project($user, 'Portfolio Refresh', $career, [
            'description' => 'Update case studies, profile copy, and selected work.',
            'icon' => 'panels-top-left',
            'background' => '#DBEAFE',
            'status' => 'active',
            'due_date' => today()->addMonth(),
        ]);

        $readingProject = $this->project($user, 'Annual Reading List', $personalGrowth, [
            'description' => 'Curate and track this year’s reading list.',
            'icon' => 'library-big',
            'background' => '#F3E8FF',
            'status' => 'active',
            'start_date' => today()->startOfYear(),
            'due_date' => today()->endOfYear(),
        ]);

        $this->project($user, 'Inbox Project', null, [
            'description' => 'An unassigned project ready to be linked to an Area.',
            'icon' => 'inbox',
            'background' => '#F1F5F9',
            'status' => 'active',
        ]);

        $runningGuide = $this->resource($user, 'Beginner 10K Training Guide', [
            'type' => 'article',
            'description' => 'A reference for structuring weekly running volume.',
            'url' => 'https://example.com/resources/10k-training-guide',
            'author' => 'Medasin Demo',
            'source' => 'Example Resources',
            'icon' => 'book-open',
            'background' => '#DCFCE7',
            'is_favorite' => true,
        ]);

        $careerBook = $this->resource($user, 'Building a Meaningful Career', [
            'type' => 'book',
            'description' => 'Notes and exercises for intentional career planning.',
            'author' => 'Alex Rivera',
            'source' => 'Personal Library',
            'icon' => 'book-marked',
            'background' => '#DBEAFE',
            'is_favorite' => true,
        ]);

        $reflectionTemplate = $this->resource($user, 'Monthly Reflection Template', [
            'type' => 'template',
            'description' => 'A compact prompt set for reviewing the previous month.',
            'url' => 'https://example.com/resources/monthly-reflection',
            'source' => 'Example Resources',
            'icon' => 'notebook-pen',
            'background' => '#F3E8FF',
            'is_favorite' => false,
        ]);

        $health->resources()->syncWithoutDetaching([$runningGuide->getKey()]);
        $career->resources()->syncWithoutDetaching([$careerBook->getKey()]);
        $personalGrowth->resources()->syncWithoutDetaching([
            $careerBook->getKey(),
            $reflectionTemplate->getKey(),
        ]);

        $fitnessProject->resources()->syncWithoutDetaching([$runningGuide->getKey()]);
        $portfolioProject->resources()->syncWithoutDetaching([$careerBook->getKey()]);
        $readingProject->resources()->syncWithoutDetaching([
            $careerBook->getKey(),
            $reflectionTemplate->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function area(User $user, string $name, array $attributes): Area
    {
        return $user->areas()->updateOrCreate(
            ['slug' => str($name)->slug()->toString()],
            ['name' => $name, ...$attributes],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $goals
     */
    private function seedGoals(Area $area, array $goals): void
    {
        foreach ($goals as $goal) {
            $area->goals()->updateOrCreate(
                ['title' => $goal['title']],
                $goal,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $habits
     */
    private function seedHabits(Area $area, array $habits): void
    {
        foreach ($habits as $habit) {
            $area->habits()->updateOrCreate(
                ['name' => $habit['name']],
                $habit,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $notes
     */
    private function seedNotes(Area $area, array $notes): void
    {
        foreach ($notes as $note) {
            $area->notes()->updateOrCreate(
                ['title' => $note['title']],
                $note,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function project(User $user, string $name, ?Area $area, array $attributes): Project
    {
        return $user->projects()->updateOrCreate(
            ['slug' => str($name)->slug()->toString()],
            ['name' => $name, 'area_id' => $area?->getKey(), ...$attributes],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resource(User $user, string $title, array $attributes): Resource
    {
        return $user->resources()->updateOrCreate(
            ['title' => $title],
            $attributes,
        );
    }
}
