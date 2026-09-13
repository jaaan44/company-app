<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Enums\ProjectMilestoneStatus;
use App\Http\Controllers\Api\V1\Projects\Concerns\AuthorizesMilestoneAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectMilestoneRequest;
use App\Http\Requests\Projects\UpdateProjectMilestoneRequest;
use App\Http\Resources\ProjectMilestoneResource;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Project Milestones (Phase 17 — Scheduler) — the minimal target-date
 * marker concept the Phase 17 roadmap dependency incorrectly assumed
 * Phase 10 had already built (see docs/DECISIONS.md). Nested under its
 * Project (mirroring Project Membership, Phase 10 — a Milestone is
 * genuinely, inherently contextual to a Project, unlike Tasks or
 * Schedule Entries). Viewing the roster requires the same visibility as
 * viewing the Project itself (AuthorizesProjectVisibility, reused
 * as-is); managing it requires Administrator (`projects.manage`) or the
 * Project's own Project Lead (AuthorizesMilestoneAccess) — no new
 * permission was introduced.
 */
class ProjectMilestoneController extends Controller
{
    use AuthorizesMilestoneAccess;

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($request, $project);

        $request->validate([
            'status' => ['sometimes', new Enum(ProjectMilestoneStatus::class)],
        ]);

        $milestones = $project->milestones()
            ->with('project')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('due_date')
            ->paginate($request->integer('per_page', 50));

        return ProjectMilestoneResource::collection($milestones);
    }

    public function store(StoreProjectMilestoneRequest $request, Project $project): JsonResponse
    {
        $this->authorizeManageMilestones($request, $project);

        $milestone = $project->milestones()->create($request->validated());

        return (new ProjectMilestoneResource($milestone->load('project')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Project $project, ProjectMilestone $milestone): ProjectMilestoneResource
    {
        $this->authorizeView($request, $project);
        $this->assertBelongsToProject($project, $milestone);

        return new ProjectMilestoneResource($milestone->load('project'));
    }

    public function update(UpdateProjectMilestoneRequest $request, Project $project, ProjectMilestone $milestone): ProjectMilestoneResource
    {
        $this->authorizeManageMilestones($request, $project);
        $this->assertBelongsToProject($project, $milestone);

        $milestone->update($request->validated());

        return new ProjectMilestoneResource($milestone->load('project'));
    }

    public function destroy(Request $request, Project $project, ProjectMilestone $milestone): JsonResponse
    {
        $this->authorizeManageMilestones($request, $project);
        $this->assertBelongsToProject($project, $milestone);

        $milestone->delete();

        return response()->json(status: 204);
    }

    /**
     * {milestone:public_id} resolves globally via implicit route model
     * binding (withoutScopedBindings() — Project has no singular
     * milestone() relation for Laravel to guess, mirroring Phase 10/16's
     * identical two-consecutive-Eloquent-parameter fix); this guards
     * against addressing a real Milestone through a different Project's
     * URL.
     */
    private function assertBelongsToProject(Project $project, ProjectMilestone $milestone): void
    {
        if ($milestone->project_id !== $project->id) {
            abort(404);
        }
    }
}
