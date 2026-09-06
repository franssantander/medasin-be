<?php

use App\Enum\FocusMood;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusSessionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('focus_minutes')->default(25);
            $table->unsignedSmallInteger('short_break_minutes')->default(5);
            $table->unsignedSmallInteger('long_break_minutes')->default(15);
            $table->unsignedTinyInteger('sessions_before_long_break')->default(4);
            $table->boolean('ask_before_next_session')->default(true);
            $table->boolean('ask_for_reflection')->default(false);
            $table->string('ambient_sound', 20)->default('off');
            $table->timestamps();
        });

        Schema::create('focus_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'completed_at', 'position']);
        });

        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('focus_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task_title', 120)->nullable();
            $table->enum('type', array_column(FocusSessionType::cases(), 'value'));
            $table->enum('status', array_column(FocusSessionStatus::cases(), 'value'));
            $table->unsignedInteger('duration_seconds');
            $table->unsignedInteger('remaining_seconds');
            $table->timestamp('started_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('mood', array_column(FocusMood::cases(), 'value'))->nullable();
            $table->text('reflection_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_sessions');
        Schema::dropIfExists('focus_tasks');
        Schema::dropIfExists('focus_settings');
    }
};
