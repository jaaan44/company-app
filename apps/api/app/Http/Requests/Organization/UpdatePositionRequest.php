<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Requests\Organization\Concerns\ResolvesDepartmentId;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePositionRequest extends FormRequest
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
        /** @var Position $position */
        $position = $this->route('position');

        $departmentId = $this->has('department_id') ? $this->resolveDepartmentId() : $position->department_id;

        return [
            'department_id' => ['sometimes', 'nullable', 'string', Rule::exists('departments', 'public_id')],
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('positions', 'title')->where(
                    fn ($query) => $departmentId
                        ? $query->where('department_id', $departmentId)
                        : $query->whereNull('department_id')
                )->ignore($position->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
