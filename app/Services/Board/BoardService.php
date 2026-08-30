<?php

namespace App\Services\Board;

use App\Enum\BoardStageKey;
use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BoardService
{
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

    public function delete(Project $project, Board $board): void
    {
        if ($project->boards()->count() <= 1) {
            throw new ConflictHttpException('A project must keep at least one board.');
        }

        DB::transaction(function () use ($project, $board): void {
            $board->tasks()->delete();
            $board->delete();

            $project->boards()->orderBy('position')->get()->each(
                fn (Board $item, int $position) => $item->update(['position' => $position]),
            );
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
}
