<?php

namespace App\Services\Reporting;

use App\Http\Controllers\Api\V1\Projects\Concerns\AuthorizesProjectVisibility;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Project visibility for Dashboard/Reports (Phase 20) — reuses
 * AuthorizesProjectVisibility::canViewAllProjects() as-is, the exact
 * predicate ProjectController itself checks (`projects.view`,
 * Administrator/Manager); the membership-scoped fallback below mirrors
 * ProjectController::index()'s own inline scoping (Project visibility
 * has never lived in a separate scopeVisible...() trait method — it was
 * always resolved directly inside that controller's index()). Never
 * aborts: unlike a single-purpose endpoint, a Dashboard/Report card with
 * no qualifying access must degrade to an empty result, not a 403 that
 * would break the rest of the response — the same adaptation
 * ScheduleController already established for Task/Leave/Milestone
 * visibility (Phase 17, see docs/DECISIONS.md DEC-040).
 */
final class ProjectVisibility
{
    use AuthorizesProjectVisibility;

    /**
     * @return Builder<Project>
     */
    public function visibleQuery(Request $request): Builder
    {
        if ($this->canViewAllProjects($request)) {
            return Project::query();
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return Project::query()->whereRaw('1 = 0');
        }

        return Project::query()->whereHas('memberships', fn (Builder $q) => $q->where('staff_id', $staff->id));
    }
}
