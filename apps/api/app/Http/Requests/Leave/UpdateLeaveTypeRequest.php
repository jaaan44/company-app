<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveTypeStatus;
use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateLeaveTypeRequest extends FormRequest
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
        /** @var LeaveType $leaveType */
        $leaveType = $this->route('leaveType');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('leave_types', 'name')->ignore($leaveType->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('leave_types', 'code')->ignore($leaveType->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_paid' => ['sometimes', 'boolean'],
            'status' => ['sometimes', new Enum(LeaveTypeStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
