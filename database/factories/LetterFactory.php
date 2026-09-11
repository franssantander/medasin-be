<?php

namespace Database\Factories;

use App\Enum\LetterStatus;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Letter>
 */
class LetterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'subtitle' => null,
            'content' => '{"version":1,"blocks":[{"type":"paragraph","content":"A letter draft."}]}',
            'content_text' => 'A letter draft.',
            'word_count' => 3,
            'read_time_minutes' => 1,
            'status' => LetterStatus::DRAFT,
        ];
    }
}
