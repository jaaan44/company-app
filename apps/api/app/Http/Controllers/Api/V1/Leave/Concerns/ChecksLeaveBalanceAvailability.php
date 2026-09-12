<?php

namespace App\Http\Controllers\Api\V1\Leave\Concerns;

use App\Models\LeaveBalance;
use App\Models\LeaveType;

/**
 * Shared by MyLeaveRequestController and LeaveRequestController — the
 * authoritative, transaction-safe re-check of paid-leave balance
 * sufficiency, run inside the caller's DB::transaction() immediately
 * before creating a Leave Request row. Applies identically to
 * self-service and Administrator-created requests: Administrator
 * creation bypasses Staff active-employment-status eligibility but never
 * balance sufficiency — there is no override/negative-balance concept in
 * this module (docs/phases/V1_PHASE_13_DEFINITION.md's Negative
 * Balances, DEC-036).
 *
 * The Form Request layer (ValidatesLeaveBalanceAvailability) already
 * performs the same calculation for a fast, well-labelled `422` before
 * this ever runs — this is the second, `lockForUpdate()`-guarded check
 * that actually prevents two concurrent submissions from both passing
 * the first check and jointly overdrawing the same balance row.
 */
trait ChecksLeaveBalanceAvailability
{
    private function assertSufficientBalance(LeaveType $leaveType, int $staffId, int $year, int $totalDays): void
    {
        if (! $leaveType->is_paid) {
            return;
        }

        LeaveBalance::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $remaining = LeaveBalance::allocatedDaysFor($staffId, $leaveType->id, $year)
            - LeaveBalance::usedDaysFor($staffId, $leaveType->id, $year)
            - LeaveBalance::pendingDaysFor($staffId, $leaveType->id, $year);

        if ($totalDays > $remaining) {
            abort(409, "Insufficient leave balance: {$remaining} day(s) remaining for this leave type in {$year}.");
        }
    }
}
