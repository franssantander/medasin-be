<?php

use App\Models\Area;
use App\Models\Board;
use App\Models\BoardTask;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Note;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_labels', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('resource_attachments', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('trash_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->uuid('subject_uuid');
            $table->string('item_type', 32);
            $table->string('title');
            $table->string('context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('deleted_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['user_id', 'deleted_at']);
            $table->index(['user_id', 'item_type']);
            $table->index('expires_at');
        });

        $insert = function (object $record, string $subjectType, string $itemType, string $title, ?string $context = null, ?array $metadata = null): void {
            $deletedAt = Carbon::parse($record->deleted_at);
            DB::table('trash_entries')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $record->user_id,
                'subject_type' => $subjectType,
                'subject_id' => $record->id,
                'subject_uuid' => $record->uuid,
                'item_type' => $itemType,
                'title' => $title,
                'context' => $context,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'deleted_at' => $deletedAt,
                'expires_at' => $deletedAt->copy()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        DB::table('areas')->whereNotNull('deleted_at')->orderBy('id')->each(
            fn (object $area) => $insert($area, Area::class, 'area', $area->name),
        );

        DB::table('projects')->leftJoin('areas', 'areas.id', '=', 'projects.area_id')
            ->whereNotNull('projects.deleted_at')
            ->select('projects.*', 'areas.name as context')
            ->orderBy('projects.id')
            ->each(fn (object $project) => $insert($project, Project::class, 'project', $project->name, $project->context));

        DB::table('boards')->leftJoin('projects', function ($join): void {
            $join->on('projects.id', '=', 'boards.context_id')->where('boards.context_type', 'project');
        })->whereNotNull('boards.deleted_at')
            ->select('boards.*', 'projects.name as context')
            ->orderBy('boards.id')
            ->each(function (object $board) use ($insert): void {
                $taskIds = DB::table('board_tasks')->where('board_id', $board->id)->whereNotNull('deleted_at')->pluck('id')->all();
                $insert($board, Board::class, 'board', $board->name, $board->context, ['task_ids' => $taskIds]);
            });

        DB::table('board_tasks')->join('boards', 'boards.id', '=', 'board_tasks.board_id')
            ->join('projects', function ($join): void {
                $join->on('projects.id', '=', 'boards.context_id')->where('boards.context_type', 'project');
            })->whereNotNull('board_tasks.deleted_at')->whereNull('boards.deleted_at')
            ->select('board_tasks.*', 'boards.user_id', DB::raw("projects.name || ' · ' || boards.name as context"))
            ->orderBy('board_tasks.id')
            ->each(fn (object $task) => $insert($task, BoardTask::class, 'task', $task->title, $task->context));

        foreach ([
            ['table' => 'goals', 'class' => Goal::class, 'type' => 'goal', 'title' => 'title'],
            ['table' => 'habits', 'class' => Habit::class, 'type' => 'habit', 'title' => 'name'],
        ] as $definition) {
            DB::table($definition['table'])->join('areas', 'areas.id', '=', $definition['table'].'.area_id')
                ->whereNotNull($definition['table'].'.deleted_at')
                ->select($definition['table'].'.*', 'areas.user_id', 'areas.name as context')
                ->orderBy($definition['table'].'.id')
                ->each(fn (object $record) => $insert($record, $definition['class'], $definition['type'], $record->{$definition['title']}, $record->context));
        }

        $deletedNotes = DB::table('notes')->join('areas', 'areas.id', '=', 'notes.area_id')
            ->whereNotNull('notes.deleted_at')
            ->select('notes.*', 'areas.user_id', 'areas.name as context')
            ->orderBy('notes.id')->get();
        $deletedIds = $deletedNotes->pluck('id')->all();
        $deletedNotes->filter(fn (object $note) => ! $note->parent_id || ! in_array($note->parent_id, $deletedIds, true))
            ->each(function (object $root) use ($deletedNotes, $insert): void {
                $ids = collect([$root->id]);
                $frontier = $ids;
                while ($frontier->isNotEmpty()) {
                    $frontier = $deletedNotes->whereIn('parent_id', $frontier)->pluck('id');
                    $ids = $ids->merge($frontier);
                }
                $insert($root, Note::class, 'note', $root->title ?: 'Untitled note', $root->context, ['note_ids' => $ids->values()->all()]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('trash_entries');

        Schema::table('resource_attachments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('board_labels', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
