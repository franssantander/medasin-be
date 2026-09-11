<?php

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
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
        Schema::create('letter_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('letter_id')->constrained()->cascadeOnDelete();
            $table->enum('format', array_column(LetterExportFormat::cases(), 'value'));
            $table->unsignedSmallInteger('canvas_width');
            $table->unsignedSmallInteger('canvas_height');
            $table->string('source_hash', 64);
            $table->enum('status', array_column(LetterExportStatus::cases(), 'value'))->default(LetterExportStatus::QUEUED->value);
            $table->json('pages')->nullable();
            $table->unsignedTinyInteger('page_count')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['letter_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('letter_exports');
    }
};
