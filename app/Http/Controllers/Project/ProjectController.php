<?php

namespace App\Http\Controllers\Project;

use App\Data\Project\ProjectAreaData;
use App\Data\Project\ProjectData;
use App\Enum\BoardStageKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectAreaRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\Project\ProjectDetailResource;
use App\Http\Resources\Project\ProjectListCardResource;
use App\Models\Project;
use App\Services\Project\ProjectService;
use App\Services\Resource\ResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
        protected ResourceService $resourceService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
        ]);
        $status = $validated['status'] ?? 'active';
        $query = $request->user()->projects();

        if ($status === 'active') {
            $query->whereNull('archived_at');
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $data = $this->withKanbanCounts($query)
            ->with(['area' => fn ($areaQuery) => $areaQuery->withCount('goals')])
            ->latest()
            ->get();

        return $this->success(ProjectListCardResource::collection($data)->resolve($request));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->projectService->create($user, ProjectData::from($request->validated()));

        return $this->success($data, 'Successfully created project.', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $data = $this->withKanbanCounts($request->user()
            ->projects()
            ->whereKey($project->getKey()))
            ->with([
                'area' => fn ($query) => $query->withCount('goals'),
                'boards' => fn ($query) => $query
                    ->withCount('tasks')
                    ->with(['stages' => fn ($stages) => $stages->withCount('tasks')]),
            ])
            ->firstOrFail();

        $resources = $request->user()->resources()
            ->whereNull('archived_at')
            ->where(function ($query) use ($data): void {
                $query
                    ->whereHas('projects', fn ($projects) => $projects->whereKey($data->getKey()))
                    ->orWhereHas('boardTasks.board', fn ($boards) => $boards
                        ->where('context_type', $data->getMorphClass())
                        ->where('context_id', $data->getKey()));
            })
            ->with(['attachments', 'tags', 'projects', 'areas'])
            ->latest('resources.created_at')
            ->get()
            ->map(fn ($resource) => $this->resourceService->serialize($resource))
            ->values()
            ->all();
        $payload = ProjectDetailResource::make($data)->resolve($request);
        $payload['resources'] = $resources;

        return $this->success($payload);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project)
    {
        $project = $request->user()->projects()->whereKey($project->getKey())->firstOrFail();
        $project->update(ProjectData::from($request->validated())->toArray());

        return $this->success($project->fresh(), 'Successfully updated project.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project)
    {
        $project = $request->user()->projects()->whereKey($project->getKey())->firstOrFail();
        $project->delete();

        return $this->success(null, 'Successfully deleted project.');
    }

    public function archive(Request $request, Project $project): JsonResponse
    {
        $project = $request->user()->projects()->whereKey($project->getKey())->firstOrFail();

        if ($project->archived_at === null) {
            $project->forceFill(['archived_at' => now()])->save();
        }

        return $this->success($project->fresh(), 'Successfully archived project.');
    }

    public function restore(Request $request, Project $project): JsonResponse
    {
        $project = $request->user()->projects()->whereKey($project->getKey())->firstOrFail();
        $result = $this->projectService->restore($project);
        $message = $result['moved_to_inbox']
            ? 'Successfully restored project to Inbox because its previous area is unavailable.'
            : 'Successfully restored project.';

        return $this->success($result['project'], $message);
    }

    public function updateArea(UpdateProjectAreaRequest $request, Project $project): JsonResponse
    {
        $project = $request->user()->projects()->whereKey($project->getKey())->firstOrFail();
        $data = $this->projectService->updateArea(
            $request->user(),
            $project,
            ProjectAreaData::from($request->validated()),
        );

        return $this->success(
            ProjectListCardResource::make($data)->resolve($request),
            'Successfully updated project area.',
        );
    }

    private function withKanbanCounts($query)
    {
        return $query->withCount([
            'boardTasks as total_tasks_count',
            'boardTasks as done_tasks_count' => fn ($tasks) => $tasks->whereHas(
                'stage',
                fn ($stages) => $stages->where('key', BoardStageKey::DONE->value),
            ),
        ]);
    }
}
