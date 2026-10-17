<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Manrope was the implicit default, so stored Manrope values cannot be told apart
     * from explicit choices; they move to Geist and can switch back in Preferences.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('font_family', 20)->default('geist')->change();
        });

        DB::table('users')->where('font_family', 'manrope')->update(['font_family' => 'geist']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('font_family', 'geist')->update(['font_family' => 'manrope']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('font_family', 20)->default('manrope')->change();
        });
    }
};
