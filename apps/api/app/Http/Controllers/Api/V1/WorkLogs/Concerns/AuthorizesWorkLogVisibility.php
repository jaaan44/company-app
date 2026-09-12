<?php

namespace App\Http\Controllers\Api\V1\WorkLogs\Concerns;

use App\Enums\ProjectMembershipRole;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\WorkLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared by WorkLogController (the supervisory/administrative surface —
 * self-service lives entirely in MyWorkLogController instead). Work Log
 * visibility is deliberately narrower than Task/Project's company-wide
 * Manager grant — it mirrors Phase 9's location.view precedent
 * (Manager scoped to direct reports only), not tasks.view/projects.view
 * (docs/phases/V1_PHASE_12_DEFINITION.md — Manager Authority):
 *
 * - Administrator sees every Work Log (via the centralized Gate::before
 *   override).
 * - A Manager holding `work-logs.view` sees only Work Logs whose
 *   performer is one of their own direct reports (Staff.manager_id).
 * - A Project Lead (no permission needed) sees, read-only, Work Logs
 *   referencing a Project they lead directly, or a Task belonging to one.
 * - Anyone else (an ordinary Staff member) has no access here at all —
 *   they use /api/v1/me/work-logs instead.
 */
trait AuthorizesWorkLogVisibility
{
    private function isAdministrator(Request $request): bool
    {
        return $request->user()?->hasRole(Role::ADMINISTRATOR) ?? false;
    }

    private function canViewAsManager(Request $request): bool
    {
        return $request->user()?->can('work-logs.view') ?? false;
    }

    /**
     * @return Collection<int, int>
     */
    private function ledProjectIds(Request $request): Collection
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            return collect();
        }

        return ProjectMembership::query()
            ->where('staff_id', $staff->id)
            ->where('role', ProjectMembershipRole::ProjectLead)
            ->pluck('project_id');
    }

    /**
     * @param  Builder<WorkLog>  $query
     * @return Builder<WorkLog>
     */
    private function scopeVisibleWorkLogs(Request $request, Builder $query): Builder
    {
        if ($this->isAdministrator($request)) {
            return $query;
        }

        $managerStaffId = $this->canViewAsManager($request) ? $request->user()?->staff?->id : null;
        $ledProjectIds = $this->ledProjectIds($request);

        if ($managerStaffId === null && $ledProjectIds->isEmpty()) {
            abort(403, 'You do not have access to view any work logs.');
        }

        return $query->where(function (Builder $query) use ($managerStaffId, $ledProjectIds) {
            $query->when(
                $managerStaffId !== null,
                fn (Builder $query) => $query->orWhereHas('staff', fn (Builder $q) => $q->where('manager_id', $managerStaffId))
            );

            $query->when(
                $ledProjectIds->isNotEmpty(),
                fn (Builder $query) => $query->orWhere(function (Builder $query) use ($ledProjectIds) {
                    $query->whereIn('project_id', $ledProjectIds)
                        ->orWhereHas('task', fn (Builder $q) => $q->whereIn('project_id', $ledProjectIds));
                })
            );
        });
    }

    private function authorizeShow(Request $request, WorkLog $workLog): void
    {
        if ($this->isAdministrator($request)) {
            return;
        }

        $staff = $request->user()?->staff;

        if ($this->canViewAsManager($request) && $staff !== null && $workLog->staff->manager_id === $staff->id) {
            return;
        }

        $workLogProjectId = $workLog->project_id ?? $workLog->task?->project_id;

        if ($workLogProjectId !== null && $this->ledProjectIds($request)->contains($workLogProjectId)) {
            return;
        }

        abort(403, 'You do not have access to view this work log.');
    }
}
