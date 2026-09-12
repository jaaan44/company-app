<?php

namespace App\Http\Requests\Leave\Concerns;

use App\Enums\StaffStatus;
use App\Models\LeaveBalance;
use App\Models\Staff;
use Illuminate\Contracts\Validation\Validator;

/**
 * Self-service Leave Request creation eligibility (Phase 13) — checked
 * only for self-service creation, never re-validated afterward (existing
 * Leave Requests are historical — see docs/phases/
 * V1_PHASE_13_DEFINITION.md). Administrator-entered Leave Requests
 * (StoreLeaveRequestRequest) deliberately do NOT run this check — an
 * Administrator is trusted to record/correct a request for a Staff
 * member who may since have become inactive or lack an allocation yet
 * on file.
 *
 * Requires ResolvesLeaveRequestReferences/ValidatesLeaveDateRangeAndOverlap
 * (resolveLeaveType()/totalDays()) on the same class.
 */
trait ValidatesSelfServiceLeaveEligibility
{
    private function validateSelfServiceEligibility(Validator $validator, Staff $performer): void
    {
        if ($performer->status !== StaffStatus::Active) {
            $validator->errors()->add('staff_id', 'Only an active staff member may submit a leave request.');

            return;
        }

        if ($validator->errors()->isNotEmpty()) {
            // A date/leave-type problem already reported — balance
            // math against an invalid range/type would be meaningless.
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

        $remaining = LeaveBalance::allocatedDaysFor($performer->id, $leaveType->id, $year)
            - LeaveBalance::usedDaysFor($performer->id, $leaveType->id, $year)
            - LeaveBalance::pendingDaysFor($performer->id, $leaveType->id, $year);

        if ($this->totalDays() > $remaining) {
            $validator->errors()->add('leave_type_id', "Insufficient leave balance: {$remaining} day(s) remaining for this leave type in {$year}.");
        }
    }
}
