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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('currency', 3);
            $table->unsignedInteger('price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('limits');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['name', 'slug'], 'plans_name');
            $table->index(['description', 'currency'], 'plans_currency');
            $table->index(['price', 'is_active'], 'plans_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};