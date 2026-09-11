<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationStatus;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateDepartmentRequest extends FormRequest
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
        /** @var Department $department */
        $department = $this->route('department');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department->id)],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
