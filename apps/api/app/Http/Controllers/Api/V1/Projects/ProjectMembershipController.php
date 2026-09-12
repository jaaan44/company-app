<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Enums\ProjectMembershipRole;
use App\Http\Controllers\Api\V1\Messaging\Concerns\SyncsProjectConversationMembership;
use App\Http\Controllers\Api\V1\Projects\Concerns\AuthorizesProjectVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectMembershipRequest;
use App\Http\Requests\Projects\UpdateProjectMembershipRequest;
use App\Http\Resources\ProjectMembershipResource;
use App\Models\Project;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Project Membership — the current roster of a Project (Phase 10).
 * Viewing the roster requires the same visibility as viewing the Project
 * itself (AuthorizesProjectVisibility); adding/changing/removing a member
 * requires `projects.manage` (Administrator-only, route middleware) — no
 * separate `project-membership.*` permission pair and no project-lead
 * self-management carve-out (see docs/phases/V1_PHASE_10_DEFINITION.md).
 * Addressed by the member's Staff `public_id` within the nested
 * collection, not an independent membership `public_id`.
 */
class ProjectMembershipController extends Controller
{
    use AuthorizesProjectVisibility, SyncsProjectConversationMembership;

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($request, $project);

        $request->validate([
            'role' => ['sometimes', new Enum(ProjectMembershipRole::class)],
        ]);

        $memberships = $project->memberships()
            ->with('staff')
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 50));

        return ProjectMembershipResource::collection($memberships);
    }

    public function store(StoreProjectMembershipRequest $request, Project $project): JsonResponse
    {
        $staffId = $this->resolveStaffId($request->validated('staff_id'));

        $membership = $project->memberships()->create([
            'staff_id' => $staffId,
            'role' => $request->validated('role', ProjectMembershipRole::Member->value),
        ]);

        // Phase 16: if this Project already has a conversation (lazily
        // created on first use), keep its membership synchronized — a
        // no-op when no conversation exists yet.
        $this->addToProjectConversationIfExists($project, $staffId);

        return (new ProjectMembershipResource($membership->load('staff')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProjectMembershipRequest $request, Project $project, Staff $staff): ProjectMembershipResource
    {
        $membership = $project->memberships()->where('staff_id', $staff->id)->firstOrFail();
        $membership->update($request->validated());

        return new ProjectMembershipResource($membership->load('staff'));
    }

    public function destroy(Project $project, Staff $staff): JsonResponse
    {
        $membership = $project->memberships()->where('staff_id', $staff->id)->firstOrFail();
        $membership->delete();

        // Phase 16: a Staff member removed from the Project roster is
        // also removed from its conversation (if one exists) — project
        // conversation membership is never independently managed.
        $this->removeFromProjectConversationIfExists($project, $staff->id);

        return response()->json(status: 204);
    }

    private function resolveStaffId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Staff::query()->where('public_id', $publicId)->value('id');
    }
}
