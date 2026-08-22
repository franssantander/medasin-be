<?php

use App\Enum\Status;
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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('area_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name');
            $table->string('slug');

            $table->text('description')->nullable();

            $table->string('icon')->nullable();
            $table->string('background', 32)->nullable();

            $table->enum('status', array_column(Status::cases(), 'value'))->default(Status::ACTIVE->value);

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'area_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};