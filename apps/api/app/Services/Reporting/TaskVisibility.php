<?php

namespace App\Services\Reporting;

use App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess;
use App\Models\ProjectMembership;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Task visibility for Dashboard/Reports (Phase 20) — reuses
 * AuthorizesTaskAccess::canViewAllTasks() as-is (the exact `tasks.view`
 * predicate TaskController itself checks). The linked-Staff fallback
 * below reproduces AuthorizesTaskAccess::authorizeView()'s exact rule
 * (Project membership OR direct assignment) as a query rather than a
 * per-instance check — the same self-contained, non-aborting predicate
 * pattern ScheduleController already established for Task visibility in
 * its own Scheduler aggregation (Phase 17's
 * ScheduleController::canViewTask()), not a new authorization rule.
 */
final class TaskVisibility
{
    use AuthorizesTaskAccess;

    /**
     * @return Builder<Task>
     */
    public function visibleQuery(Request $request): Builder
    {
        if ($this->canViewAllTasks($request)) {
            return Task::query();
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return Task::query()->whereRaw('1 = 0');
        }

        $memberProjectIds = ProjectMembership::query()
            ->where('staff_id', $staff->id)
            ->pluck('project_id');

        return Task::query()->where(function (Builder $query) use ($staff, $memberProjectIds) {
            $query->where('assignee_staff_id', $staff->id);

            if ($memberProjectIds->isNotEmpty()) {
                $query->orWhereIn('project_id', $memberProjectIds);
            }
        });
    }
}
