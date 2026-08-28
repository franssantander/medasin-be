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

        $this->callWith(AreaSeeder::class, ['user' => $testUser]);
        $this->seedAreaInterconnections($testUser);

        $this->call([
            PlanSeeder::class,
        ]);
    }

    private function seedAreaInterconnections(User $user): void
    {
        $health = $user->areas()->where('slug', 'health')->firstOrFail();
        $career = $user->areas()->where('slug', 'career')->firstOrFail();
        $personalDevelopment = $user->areas()->where('slug', 'personal-development')->firstOrFail();

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

        $this->seedGoals($personalDevelopment, [
            [
                'title' => 'Read twelve books this year',
                'description' => 'Alternate between practical nonfiction and literature.',
                'status' => GoalStatus::IN_PROGRESS,
                'start_date' => today()->startOfYear(),
                'due_date' => today()->endOfYear(),
            ],
        ]);

        $this->seedHabits($health, [
            [
                'name' => 'Morning walk',
                'icon' => 'Footprints',
                'description' => 'Walk outside before starting work.',
                'frequency' => HabitFrequency::DAILY,
                'schedule' => null,
                'is_active' => true,
            ],
            [
                'name' => 'Strength training',
                'icon' => 'Dumbbell',
                'description' => 'Complete a full-body strength session.',
                'frequency' => HabitFrequency::WEEKLY,
                'schedule' => ['days' => ['monday', 'wednesday', 'friday']],
                'is_active' => true,
            ],
        ]);

        $this->seedHabits($career, [
            [
                'name' => 'Weekly review',
                'icon' => 'ListChecks',
                'description' => 'Review priorities, blockers, and progress every Friday.',
                'frequency' => HabitFrequency::WEEKLY,
                'schedule' => ['days' => ['friday']],
                'is_active' => true,
            ],
        ]);

        $this->seedHabits($personalDevelopment, [
            [
                'name' => 'Read for thirty minutes',
                'icon' => 'BookOpen',
                'description' => 'Read without notifications or other distractions.',
                'frequency' => HabitFrequency::DAILY,
                'schedule' => null,
                'is_active' => true,
            ],
            [
                'name' => 'Monthly reflection',
                'icon' => 'NotebookPen',
                'description' => 'Capture lessons, wins, and adjustments for the next month.',
                'frequency' => HabitFrequency::MONTHLY,
                'schedule' => ['dates' => [28]],
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

        $this->seedNotes($personalDevelopment, [
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

        $readingProject = $this->project($user, 'Annual Reading List', $personalDevelopment, [
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
        $personalDevelopment->resources()->syncWithoutDetaching([
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
