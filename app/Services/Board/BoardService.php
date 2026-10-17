<?php

namespace App\Services\Board;

use App\Enum\BoardStageKey;
use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BoardService
{
    public function ensureStandaloneBoard(User $user): Board
    {
        return DB::transaction(function () use ($user): Board {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $board = $user->boards()
                ->whereNull('context_type')
                ->whereNull('context_id')
                ->orderBy('position')
                ->first();

            return $board ?: $this->createStandalone($user);
        });
    }

    public function createStandalone(User $user, ?string $name = null): Board
    {
        return DB::transaction(function () use ($user, $name) {
            $boards = $user->boards()->whereNull('context_type')->whereNull('context_id');
            $nextPosition = (int) (clone $boards)->withTrashed()->max('position') + 1;
            $board = $user->boards()->create([
                'name' => $name ?: $this->nextStandaloneName($user),
                'position' => (clone $boards)->withTrashed()->exists() ? $nextPosition : 0,
            ]);
            foreach (BoardStageKey::cases() as $position => $stage) {
                $board->stages()->create(['key' => $stage, 'name' => $stage->label(), 'position' => $position]);
            }

            return $board->loadCount('tasks')->load('stages');
        });
    }

    public function createForProject(User $user, Project $project, ?string $name = null): Board
    {
        return DB::transaction(function () use ($user, $project, $name) {
            $nextPosition = (int) $project->boards()->withTrashed()->max('position') + 1;
            $board = $project->boards()->make([
                'name' => $name ?: $this->nextDefaultName($project),
                'position' => $project->boards()->withTrashed()->exists() ? $nextPosition : 0,
            ]);
            $board->user()->associate($user);
            $board->save();

            foreach (BoardStageKey::cases() as $position => $stage) {
                $board->stages()->create([
                    'key' => $stage,
                    'name' => $stage->label(),
                    'position' => $position,
                ]);
            }

            return $board->loadCount('tasks')->load('stages');
        });
    }

    private function nextDefaultName(Project $project): string
    {
        $highest = $project->boards()
            ->withTrashed()
            ->pluck('name')
            ->map(function (string $name): int {
                preg_match('/^Board (\d+)$/', $name, $matches);

                return isset($matches[1]) ? (int) $matches[1] : 0;
            })
            ->max() ?? 0;

        return 'Board '.($highest + 1);
    }

    private function nextStandaloneName(User $user): string
    {
        $highest = $user->boards()->whereNull('context_type')->whereNull('context_id')->withTrashed()
            ->pluck('name')->map(function (string $name): int {
                preg_match('/^Board (\d+)$/', $name, $matches);

                return isset($matches[1]) ? (int) $matches[1] : 0;
            })->max() ?? 0;

        return 'Board '.($highest + 1);
    }
}
