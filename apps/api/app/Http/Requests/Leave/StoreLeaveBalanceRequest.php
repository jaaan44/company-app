<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrator upsert of a Staff member's Leave Balance allocation for
 * one Leave Type/calendar year (Phase 13). Authorization
 * (`leave-requests.manage`) is enforced by route middleware, not here.
 * Not independently addressed by a public_id — keyed by
 * (staff, leave_type_id, year), mirroring an upsert rather than a
 * conventional PUT-by-identifier update.
 */
class StoreLeaveBalanceRequest extends FormRequest
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
            'leave_type_id' => ['required', 'string', Rule::exists('leave_types', 'public_id')],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'allocated_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
