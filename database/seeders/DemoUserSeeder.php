<?php

namespace Database\Seeders;

use App\Data\Area\AreaData;
use App\Data\Journal\JournalEntryData;
use App\Data\Letter\LetterData;
use App\Data\Note\NoteData;
use App\Data\Project\ProjectData;
use App\Data\Resource\StoreResourceData;
use App\Enum\BoardLabelColor;
use App\Enum\BoardStageKey;
use App\Enum\BoardTaskPriority;
use App\Enum\FocusMood;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusSessionType;
use App\Enum\GoalStatus;
use App\Enum\HabitFrequency;
use App\Enum\PlanGrantType;
use App\Models\Area;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Resource;
use App\Models\User;
use App\Services\ApiReadCacheService;
use App\Services\Area\AreaService;
use App\Services\Board\BoardService;
use App\Services\Board\BoardTaskService;
use App\Services\Calendar\CalendarPlanService;
use App\Services\Focus\FocusService;
use App\Services\Habit\HabitService;
use App\Services\Journal\JournalService;
use App\Services\Letter\LetterService;
use App\Services\Note\NoteService;
use App\Services\Plan\PlanAssignmentService;
use App\Services\Project\ProjectService;
use App\Services\Resource\ResourceService;
use App\Services\Trash\TrashService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    private const TIMEZONE = 'Asia/Manila';

    private const PROJECT_BADGE_BACKGROUND = '#000000';

    /**
     * Seed the application's demo users and their interconnected data.
     */
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        DB::transaction(function (): void {
            foreach (['free', 'focus', 'clarity'] as $slug) {
                $email = $slug.'@example.com';
                if (User::query()->where('email', $email)->exists()) {
                    continue;
                }

                $user = User::factory()->create([
                    'first_name' => ucfirst($slug),
                    'last_name' => 'Demo',
                    'username' => $slug.'user',
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => 'password',
                ]);
                $grantType = match ($slug) {
                    'free' => PlanGrantType::FREE,
                    'focus' => PlanGrantType::RECURRING,
                    'clarity' => PlanGrantType::LIFETIME,
                };
                app(PlanAssignmentService::class)->assign(
                    $user,
                    Plan::query()->where('slug', $slug)->sole(),
                    $grantType,
                    $slug === 'focus' ? CarbonImmutable::now('UTC')->addYear() : null,
                    $slug.'-demo',
                    'demo',
                );

                if ($slug !== 'free') {
                    $this->callWith(AreaSeeder::class, ['user' => $user]);
                    $this->seedAreaInterconnections($user);
                    $this->seedUtilities($user);
                    $this->seedArchivesAndTrash($user);
                    app(ApiReadCacheService::class)->invalidateUser($user);
                }
            }
        });
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

        $this->seedHabits($user, $health, [
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

        $this->seedHabits($user, $career, [
            [
                'name' => 'Weekly review',
                'icon' => 'ListChecks',
                'description' => 'Review priorities, blockers, and progress every Friday.',
                'frequency' => HabitFrequency::WEEKLY,
                'schedule' => ['days' => ['friday']],
                'is_active' => true,
            ],
        ]);

        $this->seedHabits($user, $personalDevelopment, [
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
            'content' => $this->resourceDocument('Increase weekly running volume gradually and include recovery days.'),
            'links' => ['https://example.com/resources/10k-training-guide'],
            'tag_names' => ['Training'],
            'area_uuids' => [$health->uuid],
            'project_uuids' => [$fitnessProject->uuid],
            'icon' => 'BookOpen',
            'background' => '#DCFCE7',
        ]);

        $this->resourceWithTextAttachment($user, 'Building a Meaningful Career', [
            'content' => $this->resourceDocument('Choose meaningful work, document progress, and invest in useful skills.'),
            'tag_names' => ['Career', 'Learning'],
            'area_uuids' => [$career->uuid, $personalDevelopment->uuid],
            'project_uuids' => [$portfolioProject->uuid, $readingProject->uuid],
            'icon' => 'BookMarked',
            'background' => '#DBEAFE',
        ], 'career-notes.txt', 'List recent achievements, useful skills, and the next meaningful career step.');

        $this->resource($user, 'Monthly Reflection Template', [
            'content' => $this->resourceDocument('What went well? What did you learn? What will you change next month?'),
            'files' => [new UploadedFile(
                database_path('seeders/assets/areas/personal-development.png'),
                'reflection-template.png',
                'image/png',
                null,
                true,
            )],
            'tag_names' => ['Reflection', 'Learning'],
            'area_uuids' => [$personalDevelopment->uuid],
            'project_uuids' => [$readingProject->uuid],
            'icon' => 'NotebookPen',
            'background' => '#F3E8FF',
        ]);

        app(BoardTaskService::class)->update(
            $user,
            $fitnessProject->boards()->firstOrFail(),
            $fitnessProject->boards()->firstOrFail()->tasks()->where('title', 'Plan weekly running sessions')->sole(),
            ['resource_uuids' => [$runningGuide->uuid], 'note_uuids' => [$health->notes()->where('title', 'Health priorities')->sole()->uuid]],
        );
    }

    private function seedUtilities(User $user): void
    {
        $notes = app(NoteService::class);
        $root = $notes->create($user->standaloneNotes(), NoteData::from([
            'title' => 'Weekly planning',
            'content' => 'Choose three priorities and leave time for recovery.',
            'is_pinned' => true,
            'parent_uuid' => null,
        ]));
        $notes->create($user->standaloneNotes(), NoteData::from([
            'title' => 'Review checklist',
            'content' => 'Review projects, update next steps, and celebrate progress.',
            'parent_uuid' => $root->uuid,
        ]));
        $notes->create($user->standaloneNotes(), NoteData::from([
            'title' => 'Ideas to revisit',
            'content' => 'Keep promising ideas here until there is room to explore them.',
            'parent_uuid' => null,
        ]));

        $board = app(BoardService::class)->createStandalone($user, 'Personal Board');
        $personal = $board->labels()->create(['name' => 'Personal', 'color' => BoardLabelColor::BLUE]);
        $learning = $board->labels()->create(['name' => 'Learning', 'color' => BoardLabelColor::VIOLET]);
        $guide = $user->resources()->where('title', 'Beginner 10K Training Guide')->sole();
        $tasks = [
            $this->boardTask('Explore a weekend activity', 'Collect a few options for a refreshing weekend.', BoardStageKey::BACKLOG, BoardTaskPriority::LOW),
            $this->boardTask('Plan the coming week', 'Choose priorities using the weekly planning note.', BoardStageKey::TODOS, BoardTaskPriority::HIGH),
            $this->boardTask('Read a useful reference', 'Capture one practical idea from the training guide.', BoardStageKey::IN_PROGRESS, BoardTaskPriority::MEDIUM),
            $this->boardTask('Organize the workspace', 'Clear distractions and keep useful tools nearby.', BoardStageKey::DONE, BoardTaskPriority::LOW),
        ];
        foreach ($tasks as $index => $task) {
            app(BoardTaskService::class)->create($user, $board, [
                ...$task,
                'label_uuids' => [$index === 2 ? $learning->uuid : $personal->uuid],
                'resource_uuids' => $index === 2 ? [$guide->uuid] : [],
                'note_uuids' => $index === 1 ? [$root->uuid] : [],
            ]);
        }

        $today = CarbonImmutable::today(self::TIMEZONE);
        foreach (['Morning walk', 'Read for thirty minutes'] as $index => $name) {
            $habit = $user->habits()->where('name', $name)->sole();
            for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
                app(HabitService::class)->checkIn($user, $habit, $today->subDays($daysAgo)->toDateString(), [
                    'completed' => ! ($index === 1 && $daysAgo === 1),
                    'timezone' => self::TIMEZONE,
                ]);
            }
        }

        $this->seedFocusAndJournals($user);

        foreach ([
            ['title' => 'A letter to my future self', 'subtitle' => 'Keep making room for what matters.', 'text' => 'Remember the habits and people that make life meaningful. Keep taking small, deliberate steps.'],
            ['title' => 'A note of appreciation', 'subtitle' => null, 'text' => 'Thank you for the thoughtful work and steady support. Your kindness has made a lasting difference.'],
        ] as $letter) {
            app(LetterService::class)->create($user, LetterData::from([
                'title' => $letter['title'],
                'subtitle' => $letter['subtitle'],
                'content' => $this->blockNoteDocument($letter['text']),
            ]));
        }

        $career = $user->areas()->where('slug', 'career')->sole();
        $portfolio = $user->projects()->where('slug', 'portfolio-refresh')->sole();
        foreach ([
            ['title' => 'Daily priorities', 'date' => $today->toDateString(), 'is_all_day' => true],
            ['title' => 'Portfolio review', 'date' => $today->addDay()->toDateString(), 'is_all_day' => false, 'time' => '10:00', 'area_uuid' => $career->uuid, 'project_uuid' => $portfolio->uuid],
            ['title' => 'Review the coming week', 'date' => $today->addWeek()->toDateString(), 'is_all_day' => true, 'area_uuid' => $career->uuid],
        ] as $event) {
            app(CalendarPlanService::class)->create($user, [
                ...$event,
                'timezone' => self::TIMEZONE,
                'notes' => 'A little preparation creates space for focused work and a calmer week.',
                'reminder_offset_minutes' => null,
            ]);
        }
    }

    private function seedFocusAndJournals(User $user): void
    {
        $focus = app(FocusService::class);
        $focus->settings($user);
        $project = $user->projects()->where('slug', '10k-training-plan')->sole();
        $boardTask = $project->boards()->firstOrFail()->tasks()->where('title', 'Complete the current training week')->sole();
        $linked = $focus->createTask($user, ['board_task_uuid' => $boardTask->uuid]);
        $focus->createTask($user, ['title' => 'Draft weekly reflection']);
        $completed = $focus->createTask($user, ['title' => 'Organize reference notes']);
        $focus->updateTask($user, $completed, ['completed' => true]);

        $now = CarbonImmutable::now('UTC');
        $sessions = [];
        foreach ([$now->setTimezone(self::TIMEZONE)->subDay()->setTime(16, 0)->utc(), $now] as $index => $completedAt) {
            $task = $index === 0 ? $completed : $linked;
            $session = $user->focusSessions()->make([
                'task_title' => $task->title,
                'type' => FocusSessionType::FOCUS,
                'status' => FocusSessionStatus::COMPLETED,
                'duration_seconds' => 1500,
                'remaining_seconds' => 0,
                'started_at' => $completedAt->subMinutes(25),
                'completed_at' => $completedAt,
            ]);
            $session->focusTask()->associate($task);
            $session->saveOrFail();
            $sessions[] = $session;
        }
        $focus->reflect($user, $sessions[1], ['mood' => FocusMood::CALM->value, 'note' => 'A clear next step made this session productive.']);

        foreach ([
            ['title' => 'Weekly reflection', 'text' => 'Consistent routines helped me make progress while leaving room for rest.', 'resource' => 'Monthly Reflection Template'],
            ['title' => 'Ideas for the next release', 'text' => 'Start with the customer problem, choose a small experiment, and review what the results teach us.', 'resource' => 'Building a Meaningful Career'],
        ] as $entry) {
            app(JournalService::class)->create($user, JournalEntryData::from([
                'title' => $entry['title'],
                'content' => $this->blockNoteDocument($entry['text']),
                'resource_uuids' => [$user->resources()->where('title', $entry['resource'])->sole()->uuid],
            ]));
        }
    }

    private function seedArchivesAndTrash(User $user): void
    {
        $areas = app(AreaService::class);
        $trash = app(TrashService::class);
        $archivedArea = $areas->create($user, AreaData::from([
            'name' => 'Archived Area', 'icon' => 'Archive', 'background' => '#000000',
            'description' => 'An earlier focus area kept for reference.',
        ]));
        $areas->archive($archivedArea);
        $trashedArea = $areas->create($user, AreaData::from([
            'name' => 'Trashed Area', 'icon' => 'Archive', 'background' => '#000000',
            'description' => 'A sample area that can be recovered from Trash.',
        ]));
        $trash->delete($user, $trashedArea, 'area', $trashedArea->name);

        $attributes = ['icon' => 'Archive', 'background' => self::PROJECT_BADGE_BACKGROUND, 'status' => 'active'];
        $archivedProject = $this->project($user, 'Archived Project', null, $attributes);
        $archivedProject->forceFill(['archived_at' => now()])->saveOrFail();
        $trashedProject = $this->project($user, 'Trashed Project', null, $attributes);
        $trash->delete($user, $trashedProject, 'project', $trashedProject->name);

        $archivedResource = $this->resource($user, 'Archived Resource', [
            'content' => $this->resourceDocument('A useful reference retained after its project finished.'),
            'tag_names' => ['Reference'],
        ]);
        app(ResourceService::class)->archive($archivedResource);
        $trashedResource = $this->resourceWithTextAttachment($user, 'Trashed Resource', [
            'content' => $this->resourceDocument('Restore this resource to recover its saved attachment.'),
            'tag_names' => ['Reference'],
        ], 'recoverable-reference.txt', 'This saved reference remains available throughout the Trash recovery period.');
        $trash->delete($user, $trashedResource, 'resource', $trashedResource->title);
    }

    private function blockNoteDocument(string $text): string
    {
        return json_encode(['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => $text]]], JSON_THROW_ON_ERROR);
    }

    /** @return array{type: string, content: list<array{type: string, content: list<array{type: string, text: string}>}>} */
    private function resourceDocument(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            ],
        ];
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
    private function seedHabits(User $user, Area $area, array $habits): void
    {
        foreach ($habits as $habit) {
            $user->habits()->updateOrCreate(
                ['name' => $habit['name'], 'area_id' => $area->getKey()],
                [...$habit, 'area_id' => $area->getKey()],
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
        return app(ProjectService::class)->create($user, ProjectData::from([
            'name' => $name, 'area_uuid' => $area?->uuid, ...$attributes,
        ]));
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
        $resource = app(ResourceService::class)->create($user, StoreResourceData::from(['title' => $title, ...$attributes]));

        return $user->resources()->where('uuid', $resource['uuid'])->sole();
    }

    /** @param array<string, mixed> $attributes */
    private function resourceWithTextAttachment(User $user, string $title, array $attributes, string $filename, string $content): Resource
    {
        $path = tempnam(sys_get_temp_dir(), 'medasin-demo-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary demo attachment.');
        }

        try {
            if (file_put_contents($path, $content) === false) {
                throw new RuntimeException('Unable to write a temporary demo attachment.');
            }

            return $this->resource($user, $title, [
                ...$attributes,
                'files' => [new UploadedFile($path, $filename, 'text/plain', null, true)],
            ]);
        } finally {
            unlink($path);
        }
    }
}
