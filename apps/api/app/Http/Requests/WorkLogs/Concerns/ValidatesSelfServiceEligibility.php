<?php

namespace App\Http\Requests\WorkLogs\Concerns;

use App\Enums\StaffStatus;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator;

/**
 * Self-service Work Log creation eligibility (Phase 12) — checked only
 * once, at creation time, never re-validated afterward (existing Work
 * Logs are historical and are never invalidated by a later Project
 * Membership/Task/Staff-status change — see
 * docs/phases/V1_PHASE_12_DEFINITION.md). Administrator-entered Work
 * Logs (StoreWorkLogRequest) deliberately do NOT run this check — an
 * Administrator is trusted to backfill/correct a record for a Staff
 * member who may since have left a Project or become inactive.
 */
trait ValidatesSelfServiceEligibility
{
    /**
     * Requires ResolvesWorkLogReferences (resolveTaskId()/resolveProjectId())
     * on the same class — always used together on StoreMyWorkLogRequest.
     */
    private function validateSelfServiceEligibility(Validator $validator, Staff $performer): void
    {
        if ($performer->status !== StaffStatus::Active) {
            $validator->errors()->add('staff_id', 'Only an active staff member may log work.');

            return;
        }

        $taskId = $this->resolveTaskId();
        $projectId = $this->resolveProjectId();

        if ($taskId !== null) {
            /** @var Task|null $task */
            $task = Task::query()->find($taskId);

            if ($task === null) {
                return;
            }

            if ($task->project_id === null) {
                if ($task->assignee_staff_id !== $performer->id) {
                    $validator->errors()->add('task_id', 'You may only log work against an independent task assigned to you.');
                }

                return;
            }

            if (! $this->isProjectMember($task->project_id, $performer->id)) {
                $validator->errors()->add('task_id', 'You must be a member of this task\'s project to log work against it.');
            }

            return;
        }

        if ($projectId !== null && ! $this->isProjectMember($projectId, $performer->id)) {
            $validator->errors()->add('project_id', 'You must be a member of this project to log work against it.');
        }
    }

    private function isProjectMember(int $projectId, int $staffId): bool
    {
        return ProjectMembership::query()
            ->where('project_id', $projectId)
            ->where('staff_id', $staffId)
            ->exists();
    }
}
