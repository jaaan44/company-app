<?php

namespace App\Services\Reporting;

use App\Enums\ProjectMembershipRole;
use App\Http\Controllers\Api\V1\ServiceReports\Concerns\AuthorizesServiceReportAccess;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Service Report visibility for Dashboard/Reports (Phase 20). Mirrors
 * AuthorizesServiceReportAccess::scopeVisibleServiceReports() exactly
 * (creator, participant, creator's current Manager, linked Project's
 * Project Lead, or Administrator) — reusing that trait's own
 * isAdministrator predicate via `use`, and reproducing its composed
 * WHERE clause, adapted to return an empty result rather than
 * abort(403) when the requester has no qualifying access at all (a
 * Dashboard/Report card degrading gracefully, not the abort-per-endpoint
 * shape scopeVisibleServiceReports() was built for) — the same
 * adaptation ScheduleController already established for Task/Leave/
 * Milestone visibility (Phase 17, DEC-040).
 */
final class ServiceReportVisibility
{
    use AuthorizesServiceReportAccess;

    /**
     * @return Builder<ServiceReport>
     */
    public function visibleQuery(Request $request): Builder
    {
        if ($this->isAdministrator($request)) {
            return ServiceReport::query();
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return ServiceReport::query()->whereRaw('1 = 0');
        }

        $leadProjectIds = ProjectMembership::query()
            ->where('staff_id', $staff->id)
            ->where('role', ProjectMembershipRole::ProjectLead)
            ->pluck('project_id');

        return ServiceReport::query()->where(function (Builder $query) use ($staff, $leadProjectIds) {
            $query->where('creator_staff_id', $staff->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($staff->id))
                ->orWhereHas('creator', fn (Builder $q) => $q->where('manager_id', $staff->id));

            if ($leadProjectIds->isNotEmpty()) {
                $query->orWhereIn('project_id', $leadProjectIds);
            }
        });
    }
}
