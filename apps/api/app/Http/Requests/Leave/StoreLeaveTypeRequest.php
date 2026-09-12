<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveTypeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Permission enforcement (`leave-types.manage`) happens at the route
 * level, which runs before this request is resolved — mirroring
 * StoreDepartmentRequest.
 */
class StoreLeaveTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types', 'name')],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('leave_types', 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_paid' => ['sometimes', 'boolean'],
            'status' => ['sometimes', new Enum(LeaveTypeStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
