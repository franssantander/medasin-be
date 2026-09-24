<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->text('notes')->nullable();
            $table->date('event_date');
            $table->string('timezone', 64);
            $table->timestamp('starts_at');
            $table->boolean('is_all_day');
            $table->unsignedInteger('reminder_offset_minutes')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->uuid('reminder_token')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'starts_at', 'id']);
            $table->index(['user_id', 'event_date', 'id']);
            $table->index(['remind_at', 'notified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_plans');
    }
};
