<?php

namespace Database\Seeders;

use App\Enum\BoardStageKey;
use App\Enum\BoardTaskPriority;
use App\Enum\GoalStatus;
use App\Enum\HabitFrequency;
use App\Models\Area;
use App\Models\Project;
use App\Models\Resource;
use App\Models\User;
use App\Services\Board\BoardService;
use App\Services\Board\BoardTaskService;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    private const PROJECT_BADGE_BACKGROUND = '#000000';

    /**
     * Seed the application's demo users and their interconnected data.
     */
    public function run(): void
    {
        User::factory(10)->create();

        $testUser = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);

        $this->callWith(AreaSeeder::class, ['user' => $testUser]);
        $this->seedAreaInterconnections($testUser);
    }

    private function seedAreaInterconnections(User $user): void
    {
        $health = $user->areas()->where('slug', 'health')->firstOrFail();
        $career = $user->areas()->where('slug', 'career')->firstOrFail();
        $personalDevelopment = $user->areas()->where('slug', 'personal-development')->firstOrFail();
        $work = $user->areas()->where('slug', 'work')->firstOrFail();
        $spiritual = $user->areas()->where('slug', 'spiritual')->firstOrFail();
        $business = $user->areas()->where('slug', 'business')->firstOrFail();
        $finances = $user->areas()->where('slug', 'finances')->firstOrFail();

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
            'icon' => 'Footprints',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'start_date' => today()->subWeeks(2),
            'due_date' => today()->addWeeks(6),
        ]);

        $portfolioProject = $this->project($user, 'Portfolio Refresh', $career, [
            'description' => 'Update case studies, profile copy, and selected work.',
            'icon' => 'PanelsTopLeft',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'due_date' => today()->addMonth(),
        ]);

        $readingProject = $this->project($user, 'Annual Reading List', $personalDevelopment, [
            'description' => 'Curate and track this year’s reading list.',
            'icon' => 'LibraryBig',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'start_date' => today()->startOfYear(),
            'due_date' => today()->endOfYear(),
        ]);

        $this->project($user, 'Inbox Project', null, [
            'description' => 'An unassigned project ready to be linked to an Area.',
            'icon' => 'Inbox',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
        ]);

        $releaseProject = $this->project($user, 'Product Release Roadmap', $work, [
            'description' => 'Coordinate the next product release from planning through launch.',
            'icon' => 'Rocket',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'start_date' => today()->subWeek(),
            'due_date' => today()->addWeeks(5),
        ]);

        $emergencyFundProject = $this->project($user, 'Emergency Fund Plan', $finances, [
            'description' => 'Build a dependable emergency fund through clear monthly milestones.',
            'icon' => 'PiggyBank',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'start_date' => today()->startOfMonth(),
            'due_date' => today()->addMonths(6),
        ]);

        $businessGrowthProject = $this->project($user, 'Small Business Growth Plan', $business, [
            'description' => 'Test practical opportunities to improve customer reach and retention.',
            'icon' => 'ChartNoAxesCombined',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'due_date' => today()->addMonths(3),
        ]);

        $mindfulnessProject = $this->project($user, 'Mindfulness Practice', $spiritual, [
            'description' => 'Establish a simple and sustainable mindfulness practice.',
            'icon' => 'Sparkles',
            'background' => self::PROJECT_BADGE_BACKGROUND,
            'status' => 'active',
            'start_date' => today()->subWeeks(3),
            'due_date' => today()->addMonths(2),
        ]);

        $this->seedBoardTasks($user, $fitnessProject, [
            $this->boardTask('Choose a local 10K race', 'Compare dates, routes, and registration deadlines.', BoardStageKey::BACKLOG, BoardTaskPriority::LOW),
            $this->boardTask('Plan weekly running sessions', 'Schedule easy runs, intervals, and recovery days.', BoardStageKey::TODOS, BoardTaskPriority::HIGH),
            $this->boardTask('Complete the current training week', 'Follow the planned mileage while monitoring recovery.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::HIGH),
            $this->boardTask('Buy comfortable running shoes', 'Select shoes suited to the training volume and gait.', BoardStageKey::TODOS, BoardTaskPriority::MEDIUM),
        ]);

        $this->seedBoardTasks($user, $portfolioProject, [
            $this->boardTask('Collect testimonials', 'Request concise feedback from recent collaborators.', BoardStageKey::DONE, BoardTaskPriority::LOW),
            $this->boardTask('Rewrite profile summary', 'Describe strengths, focus areas, and measurable impact.', BoardStageKey::TODOS, BoardTaskPriority::HIGH),
            $this->boardTask('Draft release case study', 'Turn the latest release into a clear problem-and-outcome narrative.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::HIGH),
            $this->boardTask('Select featured projects', 'Choose the strongest work samples for the portfolio.', BoardStageKey::DONE, BoardTaskPriority::MEDIUM),
        ]);

        $this->seedBoardTasks($user, $readingProject, [
            $this->boardTask('Add award-winning fiction', 'Research a short list of recent literary award winners.', BoardStageKey::DONE, BoardTaskPriority::LOW),
            $this->boardTask('Choose the next book', 'Pick a title before finishing the current book.', BoardStageKey::TODOS, BoardTaskPriority::HIGH),
            $this->boardTask('Read the current selection', 'Keep a steady daily reading session and capture useful notes.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::MEDIUM),
            $this->boardTask('Organize existing book list', 'Remove duplicates and group titles by theme.', BoardStageKey::DONE, BoardTaskPriority::LOW),
        ]);

        $this->seedBoardTasks($user, $releaseProject, [
            $this->boardTask('Plan post-launch review', 'Prepare the metrics and questions for the release retrospective.', BoardStageKey::DONE, BoardTaskPriority::MEDIUM),
            $this->boardTask('Finish release checklist', 'Confirm ownership for deployment, support, and communications.', BoardStageKey::DONE, BoardTaskPriority::HIGH),
            $this->boardTask('Run final acceptance testing', 'Validate critical user journeys against the release candidate.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::HIGH),
            $this->boardTask('Confirm release scope', 'Agree on the features and fixes included in this release.', BoardStageKey::DONE, BoardTaskPriority::HIGH),
            $this->boardTask('Prepare release notes', 'Summarize customer-facing improvements and important changes.', BoardStageKey::DONE, BoardTaskPriority::MEDIUM),
        ]);

        $this->seedBoardTasks($user, $emergencyFundProject, [
            $this->boardTask('Compare savings accounts', 'Review rates, access rules, and account fees.', BoardStageKey::BACKLOG, BoardTaskPriority::LOW),
            $this->boardTask('Automate monthly transfer', 'Schedule a recurring transfer after each payday.', BoardStageKey::TODOS, BoardTaskPriority::HIGH),
            $this->boardTask('Reduce one recurring expense', 'Cancel or renegotiate a low-value monthly expense.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::MEDIUM),
            $this->boardTask('Calculate the fund target', 'Set the target from essential monthly expenses.', BoardStageKey::DONE, BoardTaskPriority::HIGH),
            $this->boardTask('Create a monthly savings report', 'Track deposits and compare the balance with the target.', BoardStageKey::BACKLOG, BoardTaskPriority::LOW),
        ]);

        $this->seedBoardTasks($user, $businessGrowthProject, [
            $this->boardTask('Research a referral program', 'Compare lightweight incentives for customer referrals.', BoardStageKey::BACKLOG, BoardTaskPriority::MEDIUM),
            $this->boardTask('Interview five customers', 'Ask customers what creates value and what causes friction.', BoardStageKey::TODOS, BoardTaskPriority::HIGH),
            $this->boardTask('Test a new landing page', 'Measure whether clearer positioning improves inquiries.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::HIGH),
            $this->boardTask('Review quarterly metrics', 'Summarize acquisition, retention, and revenue trends.', BoardStageKey::DONE, BoardTaskPriority::MEDIUM),
            $this->boardTask('Draft a retention experiment', 'Outline a small test for improving repeat customer activity.', BoardStageKey::BACKLOG, BoardTaskPriority::MEDIUM),
        ]);

        $this->seedBoardTasks($user, $mindfulnessProject, [
            $this->boardTask('Explore a guided course', 'Compare beginner-friendly mindfulness course options.', BoardStageKey::DONE, BoardTaskPriority::LOW),
            $this->boardTask('Create a quiet practice space', 'Choose a comfortable place with minimal distractions.', BoardStageKey::DONE, BoardTaskPriority::MEDIUM),
            $this->boardTask('Practice ten minutes daily', 'Use a simple breath-focused session each morning.', BoardStageKey::DONE, BoardTaskPriority::HIGH),
            $this->boardTask('Choose a meditation timer', 'Set up a timer with a gentle start and finish.', BoardStageKey::DONE, BoardTaskPriority::LOW),
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
        $project = $user->projects()->updateOrCreate(
            ['slug' => str($name)->slug()->toString()],
            ['name' => $name, 'area_id' => $area?->getKey(), ...$attributes],
        );

        if (! $project->boards()->exists()) {
            app(BoardService::class)->createForProject($user, $project);
        }

        return $project;
    }

    /**
     * @param  list<array{title: string, description: string, stage: string, priority: string}>  $tasks
     */
    private function seedBoardTasks(User $user, Project $project, array $tasks): void
    {
        $board = $project->boards()->firstOrFail();
        $taskService = app(BoardTaskService::class);

        foreach ($tasks as $task) {
            $existingTask = $board->tasks()->where('title', $task['title'])->first();

            if ($existingTask) {
                $taskService->update($user, $board, $existingTask, $task);
            } else {
                $taskService->create($user, $board, $task);
            }
        }
    }

    /**
     * @return array{title: string, description: string, stage: string, priority: string}
     */
    private function boardTask(
        string $title,
        string $description,
        BoardStageKey $stage,
        BoardTaskPriority $priority,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'stage' => $stage->value,
            'priority' => $priority->value,
        ];
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
