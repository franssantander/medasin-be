<?php

namespace Database\Factories;

use App\Enum\LetterExportFormat;
use App\Enum\LetterExportStatus;
use App\Models\Letter;
use App\Models\LetterExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LetterExport>
 */
class LetterExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'letter_id' => Letter::factory(),
            'format' => LetterExportFormat::PORTRAIT,
            'canvas_width' => 1080,
            'canvas_height' => 1350,
            'source_hash' => str_repeat('a', 64),
            'status' => LetterExportStatus::QUEUED,
            'pages' => null,
            'page_count' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
