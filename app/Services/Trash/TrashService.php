<?php

namespace App\Services\Trash;

use App\Models\Area;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardTask;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Note;
use App\Models\NoteMedia;
use App\Models\Project;
use App\Models\Resource;
use App\Models\ResourceAttachment;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TrashService
{
    public const TYPES = ['area', 'project', 'board', 'task', 'goal', 'habit', 'note', 'board_label', 'resource_attachment'];

    public function delete(User $user, Model $subject, string $itemType, string $title, ?string $context = null): TrashEntry
    {
        return DB::transaction(function () use ($user, $subject, $itemType, $title, $context): TrashEntry {
            $deletedAt = now();
            $subject->delete();

            return $this->createEntry($user, $subject, $itemType, $title, $context, [], $deletedAt);
        });
    }

    public function deleteBoard(User $user, Project $project, Board $board): TrashEntry
    {
        if ($project->boards()->count() <= 1) {
            throw new ConflictHttpException('A project must keep at least one board.');
        }

        return DB::transaction(function () use ($user, $project, $board): TrashEntry {
            $taskIds = $board->tasks()->pluck('id')->all();
            $deletedAt = now();
            $board->tasks()->delete();
            $board->delete();
            $project->boards()->orderBy('position')->get()->each(
                fn (Board $item, int $position) => $item->update(['position' => $position]),
            );

            return $this->createEntry($user, $board, 'board', $board->name, $project->name, ['task_ids' => $taskIds], $deletedAt);
        });
    }

    public function deleteNoteTree(User $user, Area $area, Note $note): TrashEntry
    {
        return DB::transaction(function () use ($user, $area, $note): TrashEntry {
            $ids = collect([$note->getKey()]);
            $frontier = $ids;
            while ($frontier->isNotEmpty()) {
                $frontier = Note::query()->whereIn('parent_id', $frontier)->pluck('id');
                $ids = $ids->merge($frontier);
            }

            $deletedAt = now();
            Note::query()->whereIn('id', $ids)->delete();

            return $this->createEntry($user, $note, 'note', $note->title ?: 'Untitled note', $area->name, ['note_ids' => $ids->values()->all()], $deletedAt);
        });
    }

    public function restore(TrashEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry = TrashEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $subject = $this->subject($entry);
            $this->ensureParentAvailable($entry, $subject);

            if ($subject instanceof Board) {
                $subject->restore();
                BoardTask::onlyTrashed()->whereIn('id', $entry->metadata['task_ids'] ?? [])->restore();
                $this->resequenceBoardTasks($subject);
                $project = Project::query()->findOrFail($subject->context_id);
                $this->resequenceBoards($project);
            } elseif ($subject instanceof Note) {
                Note::onlyTrashed()->whereIn('id', $entry->metadata['note_ids'] ?? [$subject->getKey()])->restore();
            } else {
                if ($subject instanceof BoardLabel && BoardLabel::query()->where('board_id', $subject->board_id)->where('name', $subject->name)->exists()) {
                    throw new ConflictHttpException('A label with this name already exists. Rename it before restoring this label.');
                }
                $subject->restore();
                if ($subject instanceof BoardTask) {
                    $this->resequenceBoardTasks($subject->board()->firstOrFail());
                }
            }

            $entry->delete();
        });
    }

    public function forceDelete(TrashEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry = TrashEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $subject = $this->subject($entry);

            if ($subject instanceof Area) {
                $this->purgeArea($entry, $subject);
            } elseif ($subject instanceof Project) {
                $this->purgeProject($entry, $subject);
            } elseif ($subject instanceof Board) {
                $this->purgeBoard($entry, $subject);
            } elseif ($subject instanceof Note) {
                $this->purgeNotes($entry, $entry->metadata['note_ids'] ?? [$subject->getKey()]);
            } elseif ($subject instanceof ResourceAttachment) {
                if ($subject->path) {
                    Storage::disk('local')->delete($subject->path);
                }
                $subject->forceDelete();
            } else {
                $subject->forceDelete();
            }

            $entry->delete();
        });
    }

    public function serialize(TrashEntry $entry): array
    {
        $canRestore = true;
        $reason = null;
        try {
            $this->ensureParentAvailable($entry, $this->subject($entry));
        } catch (ConflictHttpException $exception) {
            $canRestore = false;
            $reason = $exception->getMessage();
        }

        $groupSize = 1 + count($entry->metadata['task_ids'] ?? []) + max(0, count($entry->metadata['note_ids'] ?? []) - 1);

        return [
            'uuid' => $entry->uuid,
            'subject_uuid' => $entry->subject_uuid,
            'type' => $entry->item_type,
            'title' => $entry->title,
            'context' => $entry->context,
            'deleted_at' => $entry->deleted_at?->toISOString(),
            'expires_at' => $entry->expires_at?->toISOString(),
            'days_remaining' => max(0, now()->startOfDay()->diffInDays($entry->expires_at->copy()->startOfDay(), false)),
            'group_size' => $groupSize,
            'can_restore' => $canRestore,
            'restore_block_reason' => $reason,
        ];
    }

    private function createEntry(User $user, Model $subject, string $itemType, string $title, ?string $context, array $metadata, $deletedAt): TrashEntry
    {
        return TrashEntry::query()->create([
            'user_id' => $user->getKey(),
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_uuid' => $subject->uuid,
            'item_type' => $itemType,
            'title' => $title,
            'context' => $context,
            'metadata' => $metadata ?: null,
            'deleted_at' => $deletedAt,
            'expires_at' => $deletedAt->copy()->addDays(30),
        ]);
    }

    private function subject(TrashEntry $entry): Model
    {
        $class = $entry->subject_type;
        $allowed = [Area::class, Project::class, Board::class, BoardTask::class, Goal::class, Habit::class, Note::class, BoardLabel::class, ResourceAttachment::class];
        abort_unless(in_array($class, $allowed, true), 404);

        return $class::withTrashed()->findOrFail($entry->subject_id);
    }

    private function ensureParentAvailable(TrashEntry $entry, Model $subject): void
    {
        $missing = match (true) {
            $subject instanceof Project => $subject->area_id && Area::withTrashed()->find($subject->area_id)?->trashed() !== false,
            $subject instanceof Board => Project::withTrashed()->find($subject->context_id)?->trashed() !== false,
            $subject instanceof BoardTask => $this->boardUnavailable($subject->board_id),
            $subject instanceof Goal, $subject instanceof Habit, $subject instanceof Note => Area::withTrashed()->find($subject->area_id)?->trashed() !== false,
            $subject instanceof BoardLabel => $this->boardUnavailable($subject->board_id),
            $subject instanceof ResourceAttachment => Resource::withTrashed()->find($subject->resource_id)?->trashed() !== false,
            default => false,
        };

        if ($subject instanceof Note && $subject->parent_id) {
            $parent = Note::withTrashed()->find($subject->parent_id);
            $groupIds = $entry->metadata['note_ids'] ?? [];
            $missing = $missing || ($parent?->trashed() && ! in_array($parent->getKey(), $groupIds, true));
        }

        if ($missing) {
            throw new ConflictHttpException('Restore the parent item first before restoring this item.');
        }
    }

    private function boardUnavailable(int $boardId): bool
    {
        $board = Board::withTrashed()->find($boardId);

        return ! $board || $board->trashed() || Project::withTrashed()->find($board->context_id)?->trashed() !== false;
    }

    private function resequenceBoardTasks(Board $board): void
    {
        $board->tasks()->orderBy('board_stage_id')->orderBy('position')->orderBy('id')->get()->groupBy('board_stage_id')->each(
            fn ($tasks) => $tasks->values()->each(fn (BoardTask $task, int $position) => $task->forceFill(['position' => $position])->save()),
        );
    }

    private function resequenceBoards(Project $project): void
    {
        $project->boards()->orderBy('position')->orderBy('id')->get()->each(
            fn (Board $board, int $position) => $board->forceFill(['position' => $position])->save(),
        );
    }

    private function purgeArea(TrashEntry $entry, Area $area): void
    {
        $noteIds = Note::withTrashed()->where('area_id', $area->getKey())->pluck('id');
        Storage::disk('public')->delete(NoteMedia::query()->whereIn('note_id', $noteIds)->pluck('path')->all());
        if ($area->background_image) {
            Storage::disk('public')->delete($area->background_image);
        }
        $this->removeEntries(Note::class, $noteIds, $entry);
        $this->removeEntries(Goal::class, Goal::withTrashed()->where('area_id', $area->getKey())->pluck('id'), $entry);
        $this->removeEntries(Habit::class, Habit::withTrashed()->where('area_id', $area->getKey())->pluck('id'), $entry);
        $area->forceDelete();
    }

    private function purgeProject(TrashEntry $entry, Project $project): void
    {
        $boards = Board::withTrashed()->where('context_type', $project->getMorphClass())->where('context_id', $project->getKey())->get();
        $boards->each(
            fn (Board $board) => $this->purgeBoard($entry, $board),
        );
        $this->removeEntries(Board::class, $boards->pluck('id'), $entry);
        $project->forceDelete();
    }

    private function purgeBoard(TrashEntry $entry, Board $board): void
    {
        $this->removeEntries(BoardTask::class, BoardTask::withTrashed()->where('board_id', $board->getKey())->pluck('id'), $entry);
        $this->removeEntries(BoardLabel::class, BoardLabel::withTrashed()->where('board_id', $board->getKey())->pluck('id'), $entry);
        $board->forceDelete();
    }

    private function purgeNotes(TrashEntry $entry, array $ids): void
    {
        Storage::disk('public')->delete(NoteMedia::query()->whereIn('note_id', $ids)->pluck('path')->all());
        $this->removeEntries(Note::class, $ids, $entry);
        Note::withTrashed()->whereIn('id', $ids)->orderByDesc('id')->get()->each->forceDelete();
    }

    private function removeEntries(string $type, $ids, TrashEntry $except): void
    {
        TrashEntry::query()->where('subject_type', $type)->whereIn('subject_id', collect($ids)->all())->whereKeyNot($except->getKey())->delete();
    }
}
