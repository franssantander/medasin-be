<?php

namespace App\Services\Board;

use App\Enum\BoardStageKey;
use App\Models\Board;
use App\Models\BoardStage;
use App\Models\BoardTask;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardTaskService
{
    public function create(User $user, Board $board, array $data): BoardTask
    {
        return DB::transaction(function () use ($user, $board, $data) {
            $stage = $this->stage($board, $data['stage'] ?? BoardStageKey::BACKLOG->value);
            $task = $board->tasks()->make(Arr::only($data, ['title', 'description', 'priority']));
            $task->stage()->associate($stage);
            $task->position = $stage->tasks()->count();
            $task->save();

            if (array_key_exists('position', $data)) {
                $this->move($board, $task, $stage->key->value, (int) $data['position']);
            }

            $this->syncRelations($user, $board, $task, $data);

            return $this->loadTask($task);
        });
    }

    public function update(User $user, Board $board, BoardTask $task, array $data): BoardTask
    {
        return DB::transaction(function () use ($user, $board, $task, $data) {
            $task->update(Arr::only($data, ['title', 'description', 'priority']));

            if (array_key_exists('stage', $data) || array_key_exists('position', $data)) {
                $this->move(
                    $board,
                    $task,
                    $data['stage'] ?? $task->stage->key->value,
                    (int) ($data['position'] ?? $task->position),
                );
            }

            $this->syncRelations($user, $board, $task, $data);

            return $this->loadTask($task);
        });
    }

    public function move(Board $board, BoardTask $task, string $stageKey, int $position): BoardTask
    {
        return DB::transaction(function () use ($board, $task, $stageKey, $position) {
            $targetStage = $this->stage($board, $stageKey);
            $sourceStageId = $task->board_stage_id;
            $targetTasks = $targetStage->tasks()
                ->whereKeyNot($task->getKey())
                ->lockForUpdate()
                ->get();
            $position = max(0, min($position, $targetTasks->count()));
            $targetTasks->splice($position, 0, [$task]);

            $task->stage()->associate($targetStage);
            $task->save();
            $this->resequence($targetTasks);

            if ($sourceStageId !== $targetStage->getKey()) {
                $sourceTasks = BoardTask::query()
                    ->where('board_stage_id', $sourceStageId)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get();
                $this->resequence($sourceTasks);
            }

            return $this->loadTask($task);
        });
    }

    public function delete(BoardTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $stageId = $task->board_stage_id;
            $task->delete();
            $this->resequence(
                BoardTask::query()->where('board_stage_id', $stageId)->orderBy('position')->get(),
            );
        });
    }

    private function stage(Board $board, string $key): BoardStage
    {
        return $board->stages()->where('key', $key)->firstOrFail();
    }

    private function syncRelations(User $user, Board $board, BoardTask $task, array $data): void
    {
        if (array_key_exists('label_uuids', $data)) {
            $ids = $board->labels()->whereIn('uuid', $data['label_uuids'])->pluck('id');
            $this->ensureAllResolved('label_uuids', $data['label_uuids'], $ids->all());
            $task->labels()->sync($ids);
        }

        if (array_key_exists('resource_uuids', $data)) {
            $ids = $user->resources()
                ->whereNull('archived_at')
                ->whereIn('uuid', $data['resource_uuids'])
                ->pluck('id');
            $this->ensureAllResolved('resource_uuids', $data['resource_uuids'], $ids->all());
            $task->resources()->sync($ids);
        }

        if (array_key_exists('note_uuids', $data)) {
            $ids = DB::table('notes')
                ->join('areas', 'areas.id', '=', 'notes.area_id')
                ->where('areas.user_id', $user->getKey())
                ->whereNull('areas.deleted_at')
                ->whereNull('notes.deleted_at')
                ->whereIn('notes.uuid', $data['note_uuids'])
                ->pluck('notes.id');
            $this->ensureAllResolved('note_uuids', $data['note_uuids'], $ids->all());
            $task->notes()->sync($ids);
        }
    }

    private function ensureAllResolved(string $field, array $uuids, array $ids): void
    {
        if (count(array_unique($uuids)) !== count($ids)) {
            throw ValidationException::withMessages([
                $field => 'One or more selected items are unavailable.',
            ]);
        }
    }

    private function resequence(iterable $tasks): void
    {
        foreach ($tasks as $position => $task) {
            if ($task->position !== $position) {
                $task->forceFill(['position' => $position])->save();
            }
        }
    }

    private function loadTask(BoardTask $task): BoardTask
    {
        return $task->fresh()->load([
            'stage',
            'labels',
            'resources.areas',
            'notes.area',
        ]);
    }
}
