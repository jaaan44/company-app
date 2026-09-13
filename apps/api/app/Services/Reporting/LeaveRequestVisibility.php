<?php

namespace App\Services\Reporting;

use App\Http\Controllers\Api\V1\Leave\Concerns\AuthorizesLeaveRequestVisibility;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Leave Request visibility for Dashboard/Reports (Phase 20). Reuses
 * AuthorizesLeaveRequestVisibility's exact predicates (isAdministrator,
 * canViewAsManager) — the same rule LeaveRequestController's supervisory
 * surface already enforces: Administrator sees every request; a Manager
 * holding `leave-requests.view` sees their own direct reports'.
 * Deliberately ADDS a fallback for an ordinary Staff member: their own
 * Leave Requests, exactly what they already see via
 * GET /api/v1/me/leave-requests — mirrors WorkLogVisibility's identical
 * reasoning (see that class, and docs/DECISIONS.md DEC-043).
 */
final class LeaveRequestVisibility
{
    use AuthorizesLeaveRequestVisibility;

    /**
     * @return Builder<LeaveRequest>
     */
    public function visibleQuery(Request $request): Builder
    {
        if ($this->isAdministrator($request)) {
            return LeaveRequest::query();
        }

        $managerStaffId = $this->canViewAsManager($request) ? $request->user()?->staff?->id : null;

        if ($managerStaffId !== null) {
            return LeaveRequest::query()->whereHas('staff', fn (Builder $q) => $q->where('manager_id', $managerStaffId));
        }

        $staff = $request->user()?->staff;

        if ($staff !== null) {
            return LeaveRequest::query()->where('staff_id', $staff->id);
        }

        return LeaveRequest::query()->whereRaw('1 = 0');
    }
}
