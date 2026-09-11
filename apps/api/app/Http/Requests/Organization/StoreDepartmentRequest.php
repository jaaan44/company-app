<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreDepartmentRequest extends FormRequest
{
    /**
     * Permission enforcement happens at the route level
     * (`can:organization.manage`), which runs before this request is
     * resolved — mirroring the existing ApiLoginRequest pattern.
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
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
