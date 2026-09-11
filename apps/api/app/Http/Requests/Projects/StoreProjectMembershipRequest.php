<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectMembershipRole;
use App\Enums\StaffStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProjectMembershipRequest extends FormRequest
{
    /**
     * Permission enforcement happens at the route level
     * (`can:projects.manage`), which runs before this request is
     * resolved.
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
            'staff_id' => ['required', 'string', Rule::exists('staff', 'public_id')],
            'role' => ['sometimes', new Enum(ProjectMembershipRole::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateStaffEligibility($validator);
            $this->validateNotAlreadyAMember($validator);
        });
    }

    /**
     * Only an active Staff member may be newly assigned to a Project
     * (docs/phases/V1_PHASE_10_DEFINITION.md) — an existing membership is
     * left untouched if the staff member later becomes inactive/
     * separated; this check applies to new assignments only.
     */
    private function validateStaffEligibility(Validator $validator): void
    {
        $staffId = $this->resolveStaffId();

        if ($staffId === null) {
            return;
        }

        $staff = Staff::query()->find($staffId);

        if ($staff !== null && $staff->status !== StaffStatus::Active) {
            $validator->errors()->add('staff_id', 'Only active staff members may be newly assigned to a project.');
        }
    }

    /**
     * A staff member has at most one membership row per project
     * (docs/phases/V1_PHASE_10_DEFINITION.md) — this is also backed by a
     * DB unique constraint, but checking here returns a clean 422 instead
     * of a raw database constraint error.
     */
    private function validateNotAlreadyAMember(Validator $validator): void
    {
        $staffId = $this->resolveStaffId();

        if ($staffId === null) {
            return;
        }

        /** @var Project $project */
        $project = $this->route('project');

        if (ProjectMembership::query()->where('project_id', $project->id)->where('staff_id', $staffId)->exists()) {
            $validator->errors()->add('staff_id', 'This staff member is already a member of this project.');
        }
    }

    private function resolveStaffId(): ?int
    {
        $publicId = $this->input('staff_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Staff::query()->where('public_id', $publicId)->value('id');
    }
}
