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
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');
            $table->string('type')->nullable();

            $table->text('description')->nullable();
            $table->json('content')->nullable();
            $table->longText('content_text')->nullable();

            $table->text('url')->nullable();
            $table->string('author')->nullable();
            $table->string('source')->nullable();

            $table->string('icon')->nullable();
            $table->string('background', 32)->nullable();

            $table->boolean('is_favorite')->default(false);

            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'archived_at']);
        });

        Schema::create('resource_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->text('url')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });

        Schema::create('resource_tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['user_id', 'normalized_name']);
        });

        Schema::create('resource_resource_tag', function (Blueprint $table) {
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['resource_id', 'resource_tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_resource_tag');
        Schema::dropIfExists('resource_tags');
        Schema::dropIfExists('resource_attachments');
        Schema::dropIfExists('resources');
    }
};
