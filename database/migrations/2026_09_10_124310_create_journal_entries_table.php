<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('focus_session_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->longText('content');
            $table->longText('content_text')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
