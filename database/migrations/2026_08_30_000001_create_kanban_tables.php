<?php

use App\Enum\BoardLabelColor;
use App\Enum\BoardStageKey;
use App\Enum\BoardTaskPriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('context');
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });

        Schema::create('board_stages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->enum('key', array_column(BoardStageKey::cases(), 'value'));
            $table->string('name', 50);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['board_id', 'key']);
            $table->unique(['board_id', 'position']);
        });

        Schema::create('board_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_stage_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->enum('priority', array_column(BoardTaskPriority::cases(), 'value'))
                ->default(BoardTaskPriority::MEDIUM->value);
            $table->unsignedInteger('position')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['board_stage_id', 'position']);
        });

        Schema::create('board_labels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->enum('color', array_column(BoardLabelColor::cases(), 'value'));
            $table->timestamps();
            $table->unique(['board_id', 'name']);
        });

        Schema::create('board_label_task', function (Blueprint $table) {
            $table->foreignId('board_label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_task_id')->constrained()->cascadeOnDelete();
            $table->unique(['board_label_id', 'board_task_id']);
        });

        Schema::create('board_task_resource', function (Blueprint $table) {
            $table->foreignId('board_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->unique(['board_task_id', 'resource_id']);
        });

        Schema::create('board_task_note', function (Blueprint $table) {
            $table->foreignId('board_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->unique(['board_task_id', 'note_id']);
        });

        $now = now();
        DB::table('projects')->whereNull('deleted_at')->orderBy('id')->each(function ($project) use ($now): void {
            $boardId = DB::table('boards')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'user_id' => $project->user_id,
                'context_type' => 'project',
                'context_id' => $project->id,
                'name' => 'Board 1',
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (BoardStageKey::cases() as $position => $stage) {
                DB::table('board_stages')->insert([
                    'uuid' => (string) Str::uuid(),
                    'board_id' => $boardId,
                    'key' => $stage->value,
                    'name' => $stage->label(),
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_task_note');
        Schema::dropIfExists('board_task_resource');
        Schema::dropIfExists('board_label_task');
        Schema::dropIfExists('board_labels');
        Schema::dropIfExists('board_tasks');
        Schema::dropIfExists('board_stages');
        Schema::dropIfExists('boards');
    }
};
