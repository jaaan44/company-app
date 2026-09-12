<?php

namespace App\Http\Requests\Leave;

use App\Http\Requests\Leave\Concerns\ResolvesLeaveRequestReferences;
use App\Http\Requests\Leave\Concerns\ValidatesLeaveDateRangeAndOverlap;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrator-entered Leave Request, naming another Staff member as
 * requester (docs/phases/V1_PHASE_13_DEFINITION.md — Administrator
 * creation). Authorization (`leave-requests.manage`) is enforced by
 * route middleware, not here. Deliberately does NOT re-validate
 * self-service eligibility (active staff / sufficient balance) — an
 * Administrator is trusted to record/correct a historical request for a
 * Staff member who may since have become inactive or lack an allocation
 * yet on file. Leave Type validity and date-range/overlap consistency
 * ARE still enforced identically to self-service — those are structural
 * rules, not staff eligibility.
 */
class StoreLeaveRequestRequest extends FormRequest
{
    use ResolvesLeaveRequestReferences;
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
            $this->validateDateRangeAndOverlap($validator, $this->resolveStaffId());
        });
    }

    public function totalDaysResolved(): int
    {
        return $this->totalDays();
    }
}
