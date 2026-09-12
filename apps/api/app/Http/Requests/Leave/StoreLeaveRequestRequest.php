<?php

namespace App\Http\Requests\Leave;

use App\Http\Requests\Leave\Concerns\ResolvesLeaveRequestReferences;
use App\Http\Requests\Leave\Concerns\ValidatesLeaveBalanceAvailability;
use App\Http\Requests\Leave\Concerns\ValidatesLeaveDateRangeAndOverlap;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrator-entered Leave Request, naming another Staff member as
 * requester (docs/phases/V1_PHASE_13_DEFINITION.md — Administrator
 * creation). Authorization (`leave-requests.manage`) is enforced by
 * route middleware, not here.
 *
 * Administrator MAY bypass the Staff active-employment-status
 * requirement (ValidatesSelfServiceLeaveEligibility is deliberately not
 * used here) — trusted to record/correct a historical request for a
 * Staff member who has since become inactive or separated. Administrator
 * MUST NOT bypass anything else: Leave Type activity, date-range/
 * cross-year validity, overlap, and — critically — paid-leave balance
 * sufficiency (ValidatesLeaveBalanceAvailability) are all enforced
 * identically to self-service. There is no override/negative-balance
 * concept anywhere in this module (DEC-036); an Administrator who needs
 * to backfill historical paid leave with no allocation on file must
 * first set one via `POST /api/v1/staff/{public_id}/leave-balances`.
 */
class StoreLeaveRequestRequest extends FormRequest
{
    use ResolvesLeaveRequestReferences;
    use ValidatesLeaveBalanceAvailability;
    use ValidatesLeaveDateRangeAndOverlap;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'string', Rule::exists('staff', 'public_id')],
            'leave_type_id' => ['required', 'string', Rule::exists('leave_types', 'public_id')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $staffId = $this->resolveStaffId();

            $this->validateDateRangeAndOverlap($validator, $staffId);

            if ($staffId !== null) {
                $this->validateBalanceAvailability($validator, $staffId);
            }
        });
    }

    public function totalDaysResolved(): int
    {
        return $this->totalDays();
    }
}
