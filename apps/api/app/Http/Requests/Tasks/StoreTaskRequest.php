<?php

namespace App\Http\Requests\Tasks;

use App\Enums\StaffStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreTaskRequest extends FormRequest
{
    /**
     * Authorization is scoped (Administrator, or a Project Lead of the
     * target Project) and depends on the resolved project_id, so it
     * happens in TaskController after validation, not here — mirroring
     * every other Form Request in this codebase (permission enforcement
     * lives at the route/controller level, not inside authorize()).
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
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'public_id')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', new Enum(TaskStatus::class)],
            'priority' => ['sometimes', new Enum(TaskPriority::class)],
            'assignee_staff_id' => ['nullable', 'string', Rule::exists('staff', 'public_id')],
            'due_date' => ['nullable', 'date'],
            // Server-controlled only (docs/phases/V1_PHASE_11_DEFINITION.md)
            // — never accepted from client input.
            'completed_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateAssigneeEligibility($validator);
        });
    }

    /**
     * Only an active Staff member may be newly assigned to a Task; if
     * the Task belongs to a Project, the assignee must also currently be
     * a member of that Project (docs/phases/V1_PHASE_11_DEFINITION.md).
     * An independent (project-less) Task has no membership to check.
     */
    private function validateAssigneeEligibility(Validator $validator): void
    {
        $staffId = $this->resolveStaffId();

        if ($staffId === null) {
            return;
        }

        $staff = Staff::query()->find($staffId);

        if ($staff === null) {
            return;
        }

        if ($staff->status !== StaffStatus::Active) {
            $validator->errors()->add('assignee_staff_id', 'Only active staff members may be newly assigned to a task.');

            return;
        }

        $projectId = $this->resolveProjectId();

        if ($projectId === null) {
            return;
        }

        $isMember = ProjectMembership::query()
            ->where('project_id', $projectId)
            ->where('staff_id', $staffId)
            ->exists();

        if (! $isMember) {
            $validator->errors()->add('assignee_staff_id', "The assignee must be a member of the task's project.");
        }
    }

    private function resolveStaffId(): ?int
    {
        $publicId = $this->input('assignee_staff_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Staff::query()->where('public_id', $publicId)->value('id');
    }

    private function resolveProjectId(): ?int
    {
        $publicId = $this->input('project_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Project::query()->where('public_id', $publicId)->value('id');
    }
}
