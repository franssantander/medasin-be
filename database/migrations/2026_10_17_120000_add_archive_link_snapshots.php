<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving clears an Area's project links and a Resource's links; these snapshots let unarchive restore them.
     */
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->json('archived_project_ids')->nullable();
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->json('archived_links')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('archived_project_ids');
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('archived_links');
        });
    }
};
