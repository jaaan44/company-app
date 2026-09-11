<?php

namespace App\Http\Controllers\Api\V1\Projects\Concerns;

use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Shared by ProjectController and ProjectMembershipController. Project
 * visibility is not purely permission-gated (docs/phases/
 * V1_PHASE_10_DEFINITION.md): a requester holding `projects.view`
 * (Administrator/Manager) may view every Project; otherwise, a requester
 * with a linked Staff record may view only Projects where that Staff
 * record holds a Project Membership. This is the second real row-level
 * authorization pattern in this codebase, after Phase 9's Manager→
 * direct-reports scoping (CheckInController).
 */
trait AuthorizesProjectVisibility
{
    private function canViewAllProjects(Request $request): bool
    {
        return $request->user()?->can('projects.view') ?? false;
    }

    private function authorizeView(Request $request, Project $project): void
    {
        if ($this->canViewAllProjects($request)) {
            return;
        }

        $staff = $request->user()?->staff;

        if ($staff !== null && $project->memberships()->where('staff_id', $staff->id)->exists()) {
            return;
        }

        abort(403, 'You do not have access to view this project.');
    }
}
