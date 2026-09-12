<?php

namespace App\Http\Controllers\Api\V1\Leave\Concerns;

use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared by LeaveRequestController and LeaveBalanceController's
 * staff-scoped endpoints (Phase 13). Leave visibility mirrors Phase 12's
 * `work-logs.view` shape (DEC-035) but with no Project-Lead-equivalent
 * tier — Leave is an HR domain, not a Project domain (docs/phases/
 * V1_PHASE_13_DEFINITION.md's Visibility):
 *
 * - Administrator sees everything (via the centralized Gate::before
 *   override).
 * - A Manager holding `leave-requests.view` sees only Leave Requests/
 *   Balances whose Staff member is one of their own direct reports
 *   (Staff.manager_id).
 * - Anyone else (an ordinary Staff member) has no access here at all —
 *   they use /api/v1/me/leave-requests, /me/leave-balances instead.
 */
trait AuthorizesLeaveRequestVisibility
{
    private function isAdministrator(Request $request): bool
    {
        return $request->user()?->hasRole(Role::ADMINISTRATOR) ?? false;
    }

    private function canViewAsManager(Request $request): bool
    {
        return $request->user()?->can('leave-requests.view') ?? false;
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private function scopeVisibleLeaveRequests(Request $request, Builder $query): Builder
    {
        if ($this->isAdministrator($request)) {
            return $query;
        }

        $managerStaffId = $this->canViewAsManager($request) ? $request->user()?->staff?->id : null;

        if ($managerStaffId === null) {
            abort(403, 'You do not have access to view any leave requests.');
        }

        return $query->whereHas('staff', fn (Builder $q) => $q->where('manager_id', $managerStaffId));
    }

    private function authorizeShow(Request $request, LeaveRequest $leaveRequest): void
    {
        if ($this->isAdministrator($request)) {
            return;
        }

        $staff = $request->user()?->staff;

        if ($this->canViewAsManager($request) && $staff !== null && $leaveRequest->staff->manager_id === $staff->id) {
            return;
        }

        abort(403, 'You do not have access to view this leave request.');
    }

    /**
     * Whether the requester may view/act on $targetStaff's own data —
     * shared by LeaveBalanceController's staff-scoped endpoint and, via
     * isDirectManagerOf() below, approval authority.
     */
    private function authorizeManagerOf(Request $request, Staff $targetStaff): void
    {
        if ($this->isAdministrator($request)) {
            return;
        }

        if ($this->isDirectManagerOf($request, $targetStaff)) {
            return;
        }

        abort(403, 'You do not have access to this staff member\'s leave data.');
    }

    private function isDirectManagerOf(Request $request, Staff $targetStaff): bool
    {
        $staff = $request->user()?->staff;

        return $this->canViewAsManager($request) && $staff !== null && $targetStaff->manager_id === $staff->id;
    }
}
