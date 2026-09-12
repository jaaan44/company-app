<?php

namespace App\Http\Requests\Leave\Concerns;

use App\Models\LeaveBalance;
use Illuminate\Contracts\Validation\Validator;

/**
 * Paid-leave balance sufficiency (Phase 13) — applies identically to
 * self-service AND Administrator-created Leave Requests. Unlike Staff
 * active-status eligibility (ValidatesSelfServiceLeaveEligibility, which
 * only applies to self-service), Administrator creation never bypasses
 * this: the governing balance invariant
 * (`allocated_days >= approved_days + pending_days`) must hold for
 * every new request against a paid Leave Type, regardless of who
 * created it. There is no override/negative-balance concept — see
 * docs/phases/V1_PHASE_13_DEFINITION.md's Negative Balances and DEC-036.
 *
 * This is the Form Request layer's check (for a fast, well-labelled
 * `422` before any write is attempted). The authoritative, race-safe
 * check is the transactional, `lockForUpdate()`-guarded re-check in
 * `App\Http\Controllers\Api\V1\Leave\Concerns\ChecksLeaveBalanceAvailability`,
 * run by both MyLeaveRequestController and LeaveRequestController
 * immediately before creating the row.
 *
 * Requires ResolvesLeaveRequestReferences/ValidatesLeaveDateRangeAndOverlap
 * (resolveLeaveType()/totalDays()) on the same class.
 */
trait ValidatesLeaveBalanceAvailability
{
    private function validateBalanceAvailability(Validator $validator, int $staffId): void
    {
        if ($validator->errors()->isNotEmpty()) {
            // A date/leave-type/eligibility problem already reported —
            // balance math against an invalid range/type would be
            // meaningless.
            return;
        }

        $leaveType = $this->resolveLeaveType();
        $startDate = $this->date('start_date');

        if ($leaveType === null || $startDate === null || ! $leaveType->is_paid) {
            // Unpaid Leave Types are never balance-checked (docs/phases/
            // V1_PHASE_13_DEFINITION.md's Leave Balances).
            return;
        }

        $year = $startDate->year;

        $remaining = LeaveBalance::allocatedDaysFor($staffId, $leaveType->id, $year)
            - LeaveBalance::usedDaysFor($staffId, $leaveType->id, $year)
            - LeaveBalance::pendingDaysFor($staffId, $leaveType->id, $year);

        if ($this->totalDays() > $remaining) {
            $validator->errors()->add('leave_type_id', "Insufficient leave balance: {$remaining} day(s) remaining for this leave type in {$year}.");
        }
    }
}
