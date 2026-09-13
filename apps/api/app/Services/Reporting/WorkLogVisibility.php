<?php

namespace App\Services\Reporting;

use App\Http\Controllers\Api\V1\WorkLogs\Concerns\AuthorizesWorkLogVisibility;
use App\Models\WorkLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Work Log visibility for Dashboard/Reports (Phase 20). Reuses
 * AuthorizesWorkLogVisibility's exact predicates (isAdministrator,
 * canViewAsManager, ledProjectIds) — the same rule WorkLogController's
 * supervisory surface already enforces: Administrator sees every Work
 * Log; a Manager holding `work-logs.view` sees only their own direct
 * reports'; a Project Lead sees (read-only) Work Logs in Projects they
 * lead. Deliberately ADDS one fallback that trait doesn't need for its
 * own single purpose: an ordinary Staff member with none of the above
 * still sees their own Work Logs here — exactly what they already see
 * via GET /api/v1/me/work-logs (MyWorkLogController) — so Phase 20 never
 * silently shows "0 hours" to someone who does have visibility into
 * their own logged time through the existing self-service surface. This
 * is required by DEC-043's governing rule: every Phase 20 aggregate must
 * reflect exactly the union of what the requester can already see across
 * every route a source module grants them, never more and never less.
 */
final class WorkLogVisibility
{
    use AuthorizesWorkLogVisibility;

    /**
     * @return Builder<WorkLog>
     */
    public function visibleQuery(Request $request): Builder
    {
        if ($this->isAdministrator($request)) {
            return WorkLog::query();
        }

        $managerStaffId = $this->canViewAsManager($request) ? $request->user()?->staff?->id : null;
        $ledProjectIds = $this->ledProjectIds($request);

        if ($managerStaffId !== null || $ledProjectIds->isNotEmpty()) {
            return WorkLog::query()->where(function (Builder $query) use ($managerStaffId, $ledProjectIds) {
                $query->when(
                    $managerStaffId !== null,
                    fn (Builder $query) => $query->orWhereHas('staff', fn (Builder $q) => $q->where('manager_id', $managerStaffId))
                );

                $query->when(
                    $ledProjectIds->isNotEmpty(),
                    fn (Builder $query) => $query->orWhere(function (Builder $query) use ($ledProjectIds) {
                        $query->whereIn('project_id', $ledProjectIds)
                            ->orWhereHas('task', fn (Builder $q) => $q->whereIn('project_id', $ledProjectIds));
                    })
                );
            });
        }

        $staff = $request->user()?->staff;

        if ($staff !== null) {
            return WorkLog::query()->where('staff_id', $staff->id);
        }

        return WorkLog::query()->whereRaw('1 = 0');
    }
}
