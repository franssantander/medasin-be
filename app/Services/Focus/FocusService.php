<?php

namespace App\Services\Focus;

use App\Enum\BoardStageKey;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusSessionType;
use App\Models\BoardTask;
use App\Models\FocusSession;
use App\Models\FocusSetting;
use App\Models\FocusTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FocusService
{
    public function settings(User $user): FocusSetting
    {
        return $user->focusSetting()->firstOrCreate([])->refresh();
    }

    public function dashboard(User $user, string $timezone): array
    {
        $this->reconcileExpired($user);
        $settings = $this->settings($user);
        $start = now($timezone)->startOfDay()->utc();
        $end = now($timezone)->endOfDay()->utc();
        $today = $user->focusSessions()
            ->where('type', FocusSessionType::FOCUS->value)
            ->where('status', FocusSessionStatus::COMPLETED->value)
            ->whereBetween('completed_at', [$start, $end]);

        return [
            'settings' => $settings,
            'tasks' => $this->tasks($user, 'active'),
            'active_session' => $this->activeSession($user),
            'today' => [
                'completed_focus_sessions' => (clone $today)->count(),
                'focused_seconds' => (int) (clone $today)->sum('duration_seconds'),
            ],
            'suggested_next_type' => $this->suggestedNextType($user, $settings)->value,
        ];
    }

    public function tasks(User $user, string $status = 'active'): Collection
    {
        $query = $user->focusTasks()
            ->with(['boardTask.stage', 'boardTask.board.context'])
            ->withCount(['sessions as completed_focus_sessions_count' => fn ($query) => $query
                ->where('type', FocusSessionType::FOCUS->value)
                ->where('status', FocusSessionStatus::COMPLETED->value)])
            ->orderBy('position')->orderBy('created_at');

        if ($status === 'active') {
            $query->whereNull('completed_at');
        }
        if ($status === 'completed') {
            $query->whereNotNull('completed_at');
        }

        return $query->get();
    }

    public function linkableTasks(User $user, ?string $search): Collection
    {
        $linkedIds = $user->focusTasks()->whereNull('completed_at')->whereNotNull('board_task_id')->pluck('board_task_id');

        return BoardTask::query()
            ->whereNotIn('id', $linkedIds)
            ->whereHas('board', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('context_type', (new Project)->getMorphClass())
                ->whereHasMorph('context', [Project::class], fn ($project) => $project->whereNull('archived_at')))
            ->whereHas('stage', fn ($query) => $query->where('key', '!=', BoardStageKey::DONE->value))
            ->when($search, fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->with(['stage', 'board.context'])
            ->orderBy('title')->limit(50)->get();
    }

    public function createTask(User $user, array $data): FocusTask
    {
        $boardTask = isset($data['board_task_uuid']) ? $this->ownedLinkableBoardTask($user, $data['board_task_uuid']) : null;
        if ($boardTask && $user->focusTasks()->whereNull('completed_at')->where('board_task_id', $boardTask->getKey())->exists()) {
            throw ValidationException::withMessages(['board_task_uuid' => 'This board task is already in Focus.']);
        }

        $position = (int) $user->focusTasks()->max('position') + ($user->focusTasks()->exists() ? 1 : 0);
        $task = $user->focusTasks()->make(['title' => $boardTask?->title ?? $data['title'], 'position' => $position]);
        if ($boardTask) {
            $task->boardTask()->associate($boardTask);
        }
        $task->save();

        return $this->loadTask($task);
    }

    public function updateTask(User $user, FocusTask $task, array $data): FocusTask
    {
        $task = $this->ownedTask($user, $task);
        if (isset($data['title']) && $task->board_task_id) {
            throw ValidationException::withMessages(['title' => 'Rename linked tasks from their Project Board.']);
        }
        if (array_key_exists('title', $data)) {
            $task->title = $data['title'];
        }
        if (array_key_exists('completed', $data)) {
            $task->completed_at = $data['completed'] ? now() : null;
        }
        $task->save();

        return $this->loadTask($task);
    }

    public function deleteTask(User $user, FocusTask $task): void
    {
        $task = $this->ownedTask($user, $task);
        if ($user->focusSessions()->where('focus_task_id', $task->getKey())->whereIn('status', ['running', 'paused'])->exists()) {
            throw new ConflictHttpException('Reset the active session before removing this task.');
        }
        $task->delete();
    }

    public function updateSettings(User $user, array $data): FocusSetting
    {
        $settings = $this->settings($user);
        $settings->update($data);

        return $settings->refresh();
    }

    public function startSession(User $user, array $data): FocusSession
    {
        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->reconcileExpired($user);
            if ($this->activeSession($user)) {
                throw new ConflictHttpException('A focus session is already active.');
            }

            $type = FocusSessionType::from($data['type']);
            $task = null;
            if ($type === FocusSessionType::FOCUS) {
                if (empty($data['focus_task_uuid'])) {
                    throw ValidationException::withMessages(['focus_task_uuid' => 'Choose a task before starting focus.']);
                }
                $task = $user->focusTasks()->whereNull('completed_at')->where('uuid', $data['focus_task_uuid'])->firstOrFail();
            }

            $settings = $this->settings($user);
            $minutes = match ($type) {
                FocusSessionType::FOCUS => $settings->focus_minutes,
                FocusSessionType::SHORT_BREAK => $settings->short_break_minutes,
                FocusSessionType::LONG_BREAK => $settings->long_break_minutes,
            };
            $seconds = $minutes * 60;
            $session = $user->focusSessions()->make([
                'task_title' => $task?->boardTask?->title ?? $task?->title,
                'type' => $type,
                'status' => FocusSessionStatus::RUNNING,
                'duration_seconds' => $seconds,
                'remaining_seconds' => $seconds,
                'started_at' => now(),
                'ends_at' => now()->addSeconds($seconds),
            ]);
            if ($task) {
                $session->focusTask()->associate($task);
            }
            $session->save();

            return $this->loadSession($session);
        });
    }

    public function pause(User $user, FocusSession $session): FocusSession
    {
        $session = $this->ownedSession($user, $session);
        if ($session->status !== FocusSessionStatus::RUNNING) {
            throw new ConflictHttpException('Only a running session can be paused.');
        }
        if ($session->ends_at->isPast()) {
            return $this->complete($user, $session);
        }
        $session->update([
            'status' => FocusSessionStatus::PAUSED,
            'remaining_seconds' => max(1, (int) ceil(now()->diffInSeconds($session->ends_at, false))),
            'ends_at' => null,
            'paused_at' => now(),
        ]);

        return $this->loadSession($session);
    }

    public function resume(User $user, FocusSession $session): FocusSession
    {
        $session = $this->ownedSession($user, $session);
        if ($session->status !== FocusSessionStatus::PAUSED) {
            throw new ConflictHttpException('Only a paused session can be resumed.');
        }
        $session->update(['status' => FocusSessionStatus::RUNNING, 'ends_at' => now()->addSeconds($session->remaining_seconds), 'paused_at' => null]);

        return $this->loadSession($session);
    }

    public function complete(User $user, FocusSession $session): FocusSession
    {
        $session = $this->ownedSession($user, $session);
        if ($session->status === FocusSessionStatus::COMPLETED) {
            return $this->loadSession($session);
        }
        if ($session->status !== FocusSessionStatus::RUNNING || ($session->ends_at && $session->ends_at->isFuture())) {
            throw new ConflictHttpException('This session has not finished yet.');
        }
        $session->update(['status' => FocusSessionStatus::COMPLETED, 'remaining_seconds' => 0, 'ends_at' => null, 'paused_at' => null, 'completed_at' => now()]);

        return $this->loadSession($session);
    }

    public function cancel(User $user, FocusSession $session): FocusSession
    {
        $session = $this->ownedSession($user, $session);
        if (! in_array($session->status, [FocusSessionStatus::RUNNING, FocusSessionStatus::PAUSED], true)) {
            throw new ConflictHttpException('Only an active session can be reset.');
        }
        $session->update(['status' => FocusSessionStatus::CANCELLED, 'ends_at' => null, 'paused_at' => null, 'cancelled_at' => now()]);

        return $this->loadSession($session);
    }

    public function reflect(User $user, FocusSession $session, array $data): FocusSession
    {
        $session = $this->ownedSession($user, $session);
        if ($session->type !== FocusSessionType::FOCUS || $session->status !== FocusSessionStatus::COMPLETED) {
            throw new ConflictHttpException('Reflections can only be added to completed focus sessions.');
        }
        $session->update(['mood' => $data['mood'] ?? null, 'reflection_note' => $data['note'] ?? null]);

        return $this->loadSession($session);
    }

    private function reconcileExpired(User $user): void
    {
        $user->focusSessions()->where('status', FocusSessionStatus::RUNNING->value)->where('ends_at', '<=', now())->get()
            ->each(fn (FocusSession $session) => $session->update(['status' => FocusSessionStatus::COMPLETED, 'remaining_seconds' => 0, 'ends_at' => null, 'completed_at' => now()]));
    }

    private function activeSession(User $user): ?FocusSession
    {
        $session = $user->focusSessions()->whereIn('status', ['running', 'paused'])->latest()->first();

        return $session ? $this->loadSession($session) : null;
    }

    private function suggestedNextType(User $user, FocusSetting $settings): FocusSessionType
    {
        $latest = $user->focusSessions()->where('status', 'completed')->latest('completed_at')->first();
        if (! $latest || $latest->type !== FocusSessionType::FOCUS) {
            return FocusSessionType::FOCUS;
        }
        $lastLongBreak = $user->focusSessions()->where('type', 'long_break')->where('status', 'completed')->max('completed_at');
        $focusCount = $user->focusSessions()->where('type', 'focus')->where('status', 'completed')
            ->when($lastLongBreak, fn ($query) => $query->where('completed_at', '>', $lastLongBreak))->count();

        return $focusCount >= $settings->sessions_before_long_break ? FocusSessionType::LONG_BREAK : FocusSessionType::SHORT_BREAK;
    }

    private function ownedLinkableBoardTask(User $user, string $uuid): BoardTask
    {
        $task = BoardTask::query()->where('uuid', $uuid)->whereHas('board', fn ($query) => $query
            ->where('user_id', $user->getKey())->where('context_type', (new Project)->getMorphClass()))
            ->with(['stage', 'board.context'])->firstOrFail();
        if ($task->stage->key === BoardStageKey::DONE || $task->board->context?->archived_at) {
            throw ValidationException::withMessages(['board_task_uuid' => 'Choose an active Project Board task.']);
        }

        return $task;
    }

    private function ownedTask(User $user, FocusTask $task): FocusTask
    {
        return $user->focusTasks()->whereKey($task->getKey())->firstOrFail();
    }

    private function ownedSession(User $user, FocusSession $session): FocusSession
    {
        return $user->focusSessions()->whereKey($session->getKey())->firstOrFail();
    }

    private function loadTask(FocusTask $task): FocusTask
    {
        return $task->fresh()->load(['boardTask.stage', 'boardTask.board.context'])->loadCount([
            'sessions as completed_focus_sessions_count' => fn ($query) => $query->where('type', 'focus')->where('status', 'completed'),
        ]);
    }

    private function loadSession(FocusSession $session): FocusSession
    {
        return $session->fresh()->load(['focusTask.boardTask']);
    }
}
