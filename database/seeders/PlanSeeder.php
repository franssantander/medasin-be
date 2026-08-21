<?php

namespace Database\Seeders;

use App\Enum\Currency;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'A simple space to organize your thoughts, projects, and daily life.',
                'currency' => Currency::Peso,
                'price' => 0,
                'is_active' => true,
                'limits' => [
                    'journal_entries' => null,
                    'notes' => null,
                    'attachments_mb' => 500,
                    'reminders' => 10,
                    'pomodoro' => null,
                    'kanban_boards' => 3,
                    'kanban_tasks' => 100,
                ],
            ],
            [
                'name' => 'Focus',
                'slug' => 'focus',
                'description' => 'For deeper focus, better organization, and a more connected personal system.',
                'currency' => Currency::Peso,
                // TODO: confirm actual price with product before launch
                'price' => 99,
                'is_active' => true,
                'limits' => [
                    'journal_entries' => null,
                    'notes' => null,
                    'attachments_mb' => 5120,
                    'reminders' => null,
                    'pomodoro' => null,
                    'kanban_boards' => 10,
                    'kanban_tasks' => null,
                ],
            ],
            [
                'name' => 'Clarity',
                'slug' => 'clarity',
                'description' => 'For those who want the full Medasin experience with advanced tools, insights, and flexibility.',
                'currency' => Currency::Peso,
                // TODO: confirm actual price with product before launch
                'price' => 149,
                'is_active' => true,
                'limits' => [
                    'journal_entries' => null,
                    'notes' => null,
                    'attachments_mb' => 25600,
                    'reminders' => null,
                    'pomodoro' => null,
                    'kanban_boards' => null,
                    'kanban_tasks' => null,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}