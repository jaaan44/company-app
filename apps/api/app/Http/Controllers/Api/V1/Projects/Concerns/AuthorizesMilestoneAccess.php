<?php

namespace App\Http\Controllers\Api\V1\Projects\Concerns;

use App\Enums\ProjectMembershipRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use Illuminate\Http\Request;

/**
 * Shared by ProjectMilestoneController (Phase 17). Milestone visibility
 * reuses AuthorizesProjectVisibility's exact rule (current Project
 * members, plus `projects.view` holders) rather than inventing a new
 * `milestones.view` permission — "follow existing Project authorization
 * conventions as closely as possible" (docs/phases/
 * V1_PHASE_17_DEFINITION.md). Mutation authority is Administrator (via
 * the existing `projects.manage` permission — Gate::before already
 * grants this to Administrator unconditionally, so this requires no new
 * permission) or a Project Lead of the Milestone's own Project (mirrors
 * Tasks' AuthorizesTaskAccess Project Lead carve-out, DEC-034).
 */
trait AuthorizesMilestoneAccess
{
    use AuthorizesProjectVisibility;

    private function canManageAllProjects(Request $request): bool
    {
        return $request->user()?->can('projects.manage') ?? false;
    }

    private function isProjectLeadOf(Request $request, Project $project): bool
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        return ProjectMembership::query()
            ->where('project_id', $project->id)
            ->where('staff_id', $staff->id)
            ->where('role', ProjectMembershipRole::ProjectLead)
            ->exists();
    }

    private function authorizeManageMilestones(Request $request, Project $project): void
    {
        if ($this->canManageAllProjects($request)) {
            return;
        }

        if ($this->isProjectLeadOf($request, $project)) {
            return;
        }

        abort(403, 'You do not have permission to manage this project\'s milestones.');
    }
}
