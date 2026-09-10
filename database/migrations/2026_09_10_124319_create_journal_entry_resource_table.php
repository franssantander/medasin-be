<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_resource', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['journal_entry_id', 'resource_id']);
            $table->index('resource_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_resource');
    }
};
