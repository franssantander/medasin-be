<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\Project\ProjectListCardResource;
use App\Models\Project;
use App\Services\Project\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(protected ProjectService $projectService) {}

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

        $data = $query
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
        $data = $this->projectService->create($user, $request->validated());

        return $this->success($data, 'Successfully created project.', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Project $project)
    {
        $data = $request->user()
            ->projects()
            ->whereKey($project->getKey())
            ->firstOrFail();

        return $this->success($data);
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
        $project->update($request->validated());

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

        if ($project->archived_at !== null) {
            $project->forceFill(['archived_at' => null])->save();
        }

        return $this->success($project->fresh(), 'Successfully restored project.');
    }
}
