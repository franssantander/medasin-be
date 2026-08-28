<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habit_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('check_in_date');
            $table->boolean('completed');
            $table->timestamps();
            $table->unique(['habit_id', 'check_in_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_check_ins');
    }
};
