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
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Project Membership — the current roster of a Project (Phase 10).
 * Viewing the roster requires the same visibility as viewing the Project
 * itself (AuthorizesProjectVisibility); adding/changing/removing a member
 * requires `projects.manage` (Administrator-only, route middleware) — no
 * separate `project-membership.*` permission pair and no project-lead
 * self-management carve-out (see docs/phases/V1_PHASE_10_DEFINITION.md).
 * Addressed by the member's Staff `public_id` within the nested
 * collection, not an independent membership `public_id`. Add/remove/role
 * change are all audited (Phase 21, DEC-044).
 */
class ProjectMembershipController extends Controller
{
    use AuthorizesProjectVisibility, SyncsProjectConversationMembership;

    public function __construct(private readonly AuditLogger $auditLogger) {}

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

        // The business create (plus its Phase 16 conversation-membership
        // sync) and its required audit entry commit or roll back together
        // (Phase 21, DEC-044).
        $membership = DB::transaction(function () use ($request, $project, $staffId) {
            $membership = $project->memberships()->create([
                'staff_id' => $staffId,
                'role' => $request->validated('role', ProjectMembershipRole::Member->value),
            ]);
            $membership->load('staff');

            // Phase 16: if this Project already has a conversation
            // (lazily created on first use), keep its membership
            // synchronized — a no-op when no conversation exists yet.
            $this->addToProjectConversationIfExists($project, $staffId);

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::PROJECT_MEMBERSHIP_ADDED,
                entityType: 'ProjectMembership',
                entityPublicId: null,
                after: [
                    'project_id' => $project->public_id,
                    'staff_id' => $membership->staff?->public_id,
                    'role' => $membership->role->value,
                ],
            );

            return $membership;
        });

        return (new ProjectMembershipResource($membership))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProjectMembershipRequest $request, Project $project, Staff $staff): ProjectMembershipResource
    {
        $membership = $project->memberships()->where('staff_id', $staff->id)->firstOrFail();
        $beforeRole = $membership->role->value;

        DB::transaction(function () use ($request, $project, $staff, $membership, $beforeRole) {
            $membership->update($request->validated());
            $membership->load('staff');

            if ($membership->role->value !== $beforeRole) {
                $this->auditLogger->recordForRequest(
                    $request,
                    AuditActions::PROJECT_MEMBERSHIP_ROLE_CHANGED,
                    entityType: 'ProjectMembership',
                    entityPublicId: null,
                    changedFields: ['role'],
                    before: ['project_id' => $project->public_id, 'staff_id' => $staff->public_id, 'role' => $beforeRole],
                    after: ['project_id' => $project->public_id, 'staff_id' => $staff->public_id, 'role' => $membership->role->value],
                );
            }
        });

        return new ProjectMembershipResource($membership);
    }

    public function destroy(Request $request, Project $project, Staff $staff): JsonResponse
    {
        $membership = $project->memberships()->where('staff_id', $staff->id)->firstOrFail();
        $role = $membership->role->value;

        DB::transaction(function () use ($request, $project, $staff, $membership, $role) {
            $membership->delete();

            // Phase 16: a Staff member removed from the Project roster is
            // also removed from its conversation (if one exists) —
            // project conversation membership is never independently
            // managed.
            $this->removeFromProjectConversationIfExists($project, $staff->id);

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::PROJECT_MEMBERSHIP_REMOVED,
                entityType: 'ProjectMembership',
                entityPublicId: null,
                before: [
                    'project_id' => $project->public_id,
                    'staff_id' => $staff->public_id,
                    'role' => $role,
                ],
            );
        });

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
