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
        Schema::create('auth_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose');
            $table->string('code_hash');
            $table->uuid('version');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            // Rolling counters survive resends, which reset the per-code failed_attempts.
            $table->unsignedSmallInteger('recent_failures')->default(0);
            $table->timestamp('recent_failures_started_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedSmallInteger('recent_sends')->default(0);
            $table->timestamp('recent_sends_started_at')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_otps');
    }
};
