<?php

namespace App\Services\Reporting;

use App\Http\Controllers\Api\V1\IncidentReports\Concerns\AuthorizesIncidentReportAccess;
use App\Models\IncidentReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Incident Report visibility for Dashboard/Reports (Phase 20). Mirrors
 * AuthorizesIncidentReportAccess::scopeVisibleIncidentReports() exactly
 * (reporter, assigned investigator, participant, reporter's current
 * Manager, or Administrator — deliberately NO Project-Lead carve-out,
 * DEC-042) reusing that trait's isAdministrator predicate via `use`, and
 * reproducing its composed WHERE clause, adapted to return an empty
 * result rather than abort(403) — see ServiceReportVisibility's
 * identical note and ScheduleController's original precedent (Phase 17).
 *
 * This is the visibility rule DEC-043's governing instructions single
 * out by name: Dashboard/report aggregation for Incident Reports MUST be
 * derived only from reports the requester could already see through
 * GET /api/v1/incident-reports itself, and a linked Project's Project
 * Lead MUST gain no visibility here merely from that link — exactly what
 * reusing scopeVisibleIncidentReports()'s own composed rule (rather than
 * inventing a broader one) structurally guarantees.
 */
final class IncidentReportVisibility
{
    use AuthorizesIncidentReportAccess;

    /**
     * @return Builder<IncidentReport>
     */
    public function visibleQuery(Request $request): Builder
    {
        if ($this->isAdministrator($request)) {
            return IncidentReport::query();
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return IncidentReport::query()->whereRaw('1 = 0');
        }

        return IncidentReport::query()->where(function (Builder $query) use ($staff) {
            $query->where('reporter_staff_id', $staff->id)
                ->orWhere('assigned_to_staff_id', $staff->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($staff->id))
                ->orWhereHas('reporter', fn (Builder $q) => $q->where('manager_id', $staff->id));
        });
    }
}
