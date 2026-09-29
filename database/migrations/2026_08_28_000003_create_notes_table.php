<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('notes')->nullOnDelete();
            $table->string('title', 120);
            $table->longText('content');
            $table->longText('content_text')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'is_pinned']);
            $table->index(['area_id', 'is_pinned']);
            $table->index(['area_id', 'parent_id']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
