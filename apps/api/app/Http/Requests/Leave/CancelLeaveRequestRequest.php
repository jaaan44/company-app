<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling a Leave Request (Phase 13) — used by both the self-service
 * (`/me/leave-requests/{public_id}/cancel`) and Administrator
 * (`/leave-requests/{public_id}/cancel`) action endpoints. Authority and
 * the pending/approved-before-start timing rule are enforced in the
 * respective controllers, not here. A reason is optional.
 */
class CancelLeaveRequestRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
