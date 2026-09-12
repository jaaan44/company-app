<?php

namespace App\Http\Controllers\Api\V1\Tasks\Concerns;

use App\Enums\ProjectMembershipRole;
use App\Models\ProjectMembership;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Shared by TaskController. Task visibility and management are not
 * purely permission-gated (docs/phases/V1_PHASE_11_DEFINITION.md) —
 * mirroring Phase 10's AuthorizesProjectVisibility, but extended to
 * writes (create/update), not just reads, since a Project Lead needs
 * scoped management authority without holding the global `tasks.manage`
 * permission:
 *
 * - `tasks.view` (Administrator/Manager) sees every Task.
 * - Otherwise, a requester with a linked Staff record sees Tasks in
 *   Projects where they hold a Project Membership, plus any Task
 *   assigned to them directly (including an independent, project-less
 *   Task assigned to them).
 * - `tasks.manage` (Administrator-only) may create/update/delete any
 *   Task.
 * - A Project Lead (ProjectMembershipRole::ProjectLead on the Task's own
 *   Project) may create/update Tasks scoped to that Project — never a
 *   global authority, and never applicable to an independent Task (no
 *   Project to be lead of).
 * - The Task's own assignee may update only its `status` field
 *   (self-service) — enforced by the caller inspecting this method's
 *   boolean return, not a separate permission.
 */
trait AuthorizesTaskAccess
{
    private function canViewAllTasks(Request $request): bool
    {
        return $request->user()?->can('tasks.view') ?? false;
    }

    private function canManageAllTasks(Request $request): bool
    {
        return $request->user()?->can('tasks.manage') ?? false;
    }

    private function isProjectLeadOf(Request $request, ?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        return ProjectMembership::query()
            ->where('project_id', $projectId)
            ->where('staff_id', $staff->id)
            ->where('role', ProjectMembershipRole::ProjectLead)
            ->exists();
    }

    private function isMemberOf(Request $request, ?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        return ProjectMembership::query()
            ->where('project_id', $projectId)
            ->where('staff_id', $staff->id)
            ->exists();
    }

    private function authorizeView(Request $request, Task $task): void
    {
        if ($this->canViewAllTasks($request)) {
            return;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            abort(403, 'You do not have access to view this task.');
        }

        if ($this->isMemberOf($request, $task->project_id)) {
            return;
        }

        if ($task->assignee_staff_id === $staff->id) {
            return;
        }

        abort(403, 'You do not have access to view this task.');
    }

    private function authorizeCreate(Request $request, ?int $projectId): void
    {
        if ($this->canManageAllTasks($request)) {
            return;
        }

        if ($this->isProjectLeadOf($request, $projectId)) {
            return;
        }

        abort(403, 'You do not have permission to create a task here.');
    }

    /**
     * Returns true for full field-level access, false for status-only
     * self-service access (the requester is only the Task's assignee).
     * Aborts (403) if the requester has neither.
     */
    private function authorizeUpdate(Request $request, Task $task): bool
    {
        if ($this->canManageAllTasks($request)) {
            return true;
        }

        if ($this->isProjectLeadOf($request, $task->project_id)) {
            return true;
        }

        $staff = $request->user()?->staff;

        if ($staff !== null && $task->assignee_staff_id === $staff->id) {
            return false;
        }

        abort(403, 'You do not have permission to update this task.');
    }
}
