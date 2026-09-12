<?php

use App\Enum\LetterExportFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE letter_exports DROP CONSTRAINT IF EXISTS letter_exports_format_check');
            DB::statement("ALTER TABLE letter_exports ADD CONSTRAINT letter_exports_format_check CHECK (format IN ('portrait', 'square', 'story', 'landscape'))");

            return;
        }

        Schema::table('letter_exports', function (Blueprint $table) {
            $table->string('format')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('letter_exports')->where('format', 'story')->update(['format' => 'portrait']);
        DB::table('letter_exports')->where('format', 'landscape')->update(['format' => 'square']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE letter_exports DROP CONSTRAINT IF EXISTS letter_exports_format_check');
            DB::statement("ALTER TABLE letter_exports ADD CONSTRAINT letter_exports_format_check CHECK (format IN ('portrait', 'square'))");

            return;
        }

        Schema::table('letter_exports', function (Blueprint $table) {
            $table->enum('format', [
                LetterExportFormat::PORTRAIT->value,
                LetterExportFormat::SQUARE->value,
            ])->change();
        });
    }
};
