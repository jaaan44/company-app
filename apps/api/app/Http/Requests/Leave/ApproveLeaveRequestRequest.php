<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a pending Leave Request (Phase 13). Authority (Administrator
 * or the requester's direct Manager) is enforced in the controller, not
 * here. A note is optional — unlike rejection, no accountability
 * requirement demands one.
 */
class ApproveLeaveRequestRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
