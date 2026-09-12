<?php

namespace App\Http\Requests\Tasks;

use App\Enums\StaffStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Field-level authorization (full access vs. assignee status-only
     * self-service) depends on both the requester's role and the
     * resolved Task, so it happens in TaskController after validation —
     * see App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess.
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
            // A Task's Project link is fixed at creation (docs/phases/
            // V1_PHASE_11_DEFINITION.md) — moving a Task between Projects
            // is out of scope for V1 (it would need to re-validate
            // assignee membership and re-derive management authority);
            // reject explicitly rather than silently ignoring it.
            'project_id' => ['prohibited'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', new Enum(TaskStatus::class)],
            'priority' => ['sometimes', new Enum(TaskPriority::class)],
            'assignee_staff_id' => ['sometimes', 'nullable', 'string', Rule::exists('staff', 'public_id')],
            'due_date' => ['sometimes', 'nullable', 'date'],
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
     * a member of that Project. Unassigning (explicit null) skips this
     * check entirely. An existing assignment is never re-validated
     * merely because the assignee's employment status or Project
     * Membership later changed (docs/phases/V1_PHASE_11_DEFINITION.md).
     */
    private function validateAssigneeEligibility(Validator $validator): void
    {
        if (! $this->filled('assignee_staff_id')) {
            return;
        }

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

        /** @var Task $task */
        $task = $this->route('task');

        if ($task->project_id === null) {
            return;
        }

        $isMember = ProjectMembership::query()
            ->where('project_id', $task->project_id)
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
}
