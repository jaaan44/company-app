<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Requests\Organization\Concerns\ResolvesDepartmentId;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateTeamRequest extends FormRequest
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
        /** @var Team $team */
        $team = $this->route('team');

        // A partial update that doesn't touch department_id keeps the
        // team's current department for the purpose of scoping the name
        // uniqueness check below.
        $departmentId = $this->has('department_id') ? $this->resolveDepartmentId() : $team->department_id;

        return [
            'department_id' => ['sometimes', 'nullable', 'string', Rule::exists('departments', 'public_id')],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')->where(
                    fn ($query) => $departmentId
                        ? $query->where('department_id', $departmentId)
                        : $query->whereNull('department_id')
                )->ignore($team->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
