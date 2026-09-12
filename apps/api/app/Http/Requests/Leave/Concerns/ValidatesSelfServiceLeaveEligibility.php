<?php

namespace App\Http\Requests\Leave\Concerns;

use App\Enums\StaffStatus;
use App\Models\Staff;
use Illuminate\Contracts\Validation\Validator;

/**
 * Self-service Leave Request creation eligibility (Phase 13) — the
 * requester's own Staff record must be `active`. Checked only for
 * self-service creation, never re-validated afterward (existing Leave
 * Requests are historical — see docs/phases/V1_PHASE_13_DEFINITION.md).
 * Administrator-entered Leave Requests (StoreLeaveRequestRequest)
 * deliberately do NOT run this check — an Administrator is trusted to
 * record/correct a historical request for a Staff member who may since
 * have become inactive or separated.
 *
 * This is strictly an *employment-eligibility* check — it is
 * deliberately separate from balance sufficiency
 * (ValidatesLeaveBalanceAvailability), which applies identically to
 * self-service AND Administrator creation. Administrator may bypass
 * this check; Administrator may never bypass the balance check.
 */
trait ValidatesSelfServiceLeaveEligibility
{
    private function validateSelfServiceEligibility(Validator $validator, Staff $performer): void
    {
        if ($performer->status !== StaffStatus::Active) {
            $validator->errors()->add('staff_id', 'Only an active staff member may submit a leave request.');
        }
    }
}
