<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('area_id')
                ->constrained('notes')
                ->nullOnDelete();
            $table->index(['area_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex(['area_id', 'parent_id']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
