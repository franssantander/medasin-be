<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardStage;
use App\Models\BoardTask;
use App\Models\CalendarPlan;
use App\Models\FocusSession;
use App\Models\FocusSetting;
use App\Models\FocusTask;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitCheckIn;
use App\Models\JournalEntry;
use App\Models\Letter;
use App\Models\LetterExport;
use App\Models\LetterMedia;
use App\Models\Note;
use App\Models\NoteMedia;
use App\Models\Plan;
use App\Models\PlanAssignment;
use App\Models\Project;
use App\Models\Resource;
use App\Models\ResourceAttachment;
use App\Models\ResourceTag;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use WeakMap;

class ApiReadCacheInvalidator
{
    /**
     * @var list<class-string<Model>>
     */
    private const OWNED_MODELS = [
        Area::class, Board::class, CalendarPlan::class, FocusSession::class,
        FocusSetting::class, FocusTask::class, Habit::class, JournalEntry::class,
        Letter::class, Note::class, PlanAssignment::class, Project::class, Resource::class,
        ResourceTag::class, TrashEntry::class,
    ];

    /** @var array<class-string<Model>, array{column: string, table: string}> */
    private const PARENTS = [
        BoardLabel::class => ['column' => 'board_id', 'table' => 'boards'],
        BoardStage::class => ['column' => 'board_id', 'table' => 'boards'],
        BoardTask::class => ['column' => 'board_id', 'table' => 'boards'],
        Goal::class => ['column' => 'area_id', 'table' => 'areas'],
        HabitCheckIn::class => ['column' => 'habit_id', 'table' => 'habits'],
        LetterExport::class => ['column' => 'letter_id', 'table' => 'letters'],
        LetterMedia::class => ['column' => 'letter_id', 'table' => 'letters'],
        NoteMedia::class => ['column' => 'note_id', 'table' => 'notes'],
        ResourceAttachment::class => ['column' => 'resource_id', 'table' => 'resources'],
    ];

    /** @var WeakMap<Model, list<string>> */
    private WeakMap $deletingScopes;

    public function __construct(private readonly ApiReadCacheService $cache)
    {
        $this->deletingScopes = new WeakMap;
    }

    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            $this->invalidate($this->scopes($model));
        }
    }

    public function deleting(Model $model): void
    {
        $this->deletingScopes[$model] = $this->scopes($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($this->deletingScopes[$model] ?? $this->scopes($model));
        unset($this->deletingScopes[$model]);
    }

    /** @return list<string> */
    private function scopes(Model $model): array
    {
        if ($model instanceof Plan) {
            return ['plans'];
        }
        if ($model instanceof User) {
            return ['user:'.$model->uuid];
        }

        $connection = $model->getConnection();
        $ownerIds = [];
        if (in_array($model::class, self::OWNED_MODELS, true)) {
            $ownerIds = [$model->user_id, $model->getRawOriginal('user_id')];
        } elseif ($parent = self::PARENTS[$model::class] ?? null) {
            $ownerIds = $connection->table($parent['table'])
                ->whereIn('id', array_filter([
                    $model->getAttribute($parent['column']),
                    $model->getRawOriginal($parent['column']),
                ]))->pluck('user_id')->all();
        } elseif ($model instanceof DatabaseNotification && in_array($model->notifiable_type, [User::class, (new User)->getMorphClass()], true)) {
            $ownerIds = [$model->notifiable_id];
        }

        if ($ownerIds === []) {
            return [];
        }

        return $connection->table('users')->whereIn('id', array_unique(array_filter($ownerIds)))
            ->pluck('uuid')->map(fn (string $uuid): string => 'user:'.$uuid)->all();
    }

    /** @param list<string> $scopes */
    private function invalidate(array $scopes): void
    {
        foreach ($scopes as $scope) {
            $this->cache->invalidateScope($scope);
        }
    }
}
