<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting a pending Leave Request (Phase 13). Authority (Administrator
 * or the requester's direct Manager) is enforced in the controller, not
 * here. A reason is required — accountability for a decision the
 * requester will see, at negligible added complexity (docs/phases/
 * V1_PHASE_13_DEFINITION.md's Rejection Reason).
 */
class RejectLeaveRequestRequest extends FormRequest
{
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
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
