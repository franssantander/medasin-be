<?php

use App\Enum\HabitFrequency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('icon', 50)->default('Repeat2');
            $table->text('description')->nullable();
            $table->enum('frequency', array_column(HabitFrequency::cases(), 'value'))->default(HabitFrequency::DAILY->value);
            $table->json('schedule')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['area_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};
