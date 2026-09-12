<?php

namespace App\Http\Requests\Leave;

use App\Http\Requests\Leave\Concerns\ResolvesLeaveRequestReferences;
use App\Http\Requests\Leave\Concerns\ValidatesLeaveDateRangeAndOverlap;
use App\Http\Requests\Leave\Concerns\ValidatesSelfServiceLeaveEligibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-service Leave Request creation (Phase 13) — the requester is
 * always the authenticated User's own linked Staff record (resolved in
 * MyLeaveRequestController via RequiresLinkedStaff), never a
 * client-supplied staff_id. Reuses Phase 9/12's secure self-service
 * precedent.
 */
class StoreMyLeaveRequestRequest extends FormRequest
{
    use ResolvesLeaveRequestReferences;
    use ValidatesLeaveDateRangeAndOverlap;
    use ValidatesSelfServiceLeaveEligibility;

    /**
     * Requiring a linked, active Staff record is enforced by the
     * controller/eligibility validation below, not here — mirroring
     * every other Form Request in this codebase.
     */
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
            'leave_type_id' => ['required', 'string', Rule::exists('leave_types', 'public_id')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $performer = $this->user()->staff;

            if ($performer === null) {
                return;
            }

            $this->validateDateRangeAndOverlap($validator, $performer->id);
            $this->validateSelfServiceEligibility($validator, $performer);
        });
    }

    public function totalDaysResolved(): int
    {
        return $this->totalDays();
    }
}
