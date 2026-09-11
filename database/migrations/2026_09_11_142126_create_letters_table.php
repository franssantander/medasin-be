<?php

use App\Enum\LetterStatus;
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
        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('subtitle', 240)->nullable();
            $table->longText('content');
            $table->longText('content_text')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('read_time_minutes')->default(0);
            $table->enum('status', array_column(LetterStatus::cases(), 'value'))->default(LetterStatus::DRAFT->value);
            $table->timestamp('exported_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
