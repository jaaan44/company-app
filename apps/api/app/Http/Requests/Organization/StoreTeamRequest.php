<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Requests\Organization\Concerns\ResolvesDepartmentId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreTeamRequest extends FormRequest
{
    use ResolvesDepartmentId;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $departmentId = $this->resolveDepartmentId();

        return [
            'department_id' => ['nullable', 'string', Rule::exists('departments', 'public_id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')->where(
                    fn ($query) => $departmentId
                        ? $query->where('department_id', $departmentId)
                        : $query->whereNull('department_id')
                ),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
