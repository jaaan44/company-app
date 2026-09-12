<?php

namespace App\Http\Controllers\Api\V1\Scheduling\Concerns;

use App\Enums\ProjectMembershipRole;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\ScheduleEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared by ScheduleEntryController (and reused by ScheduleController's
 * aggregation query). No new permission was introduced for Schedule
 * Entries — visibility/authority is resolved entirely in-controller,
 * mirroring Tasks' AuthorizesTaskAccess (DEC-034) rather than a bare
 * `schedule.view`/`schedule.manage` permission pair, per the governing
 * Phase 17 instructions' explicit direction to prefer established
 * row-level patterns over a blanket permission that would expose private
 * entries (docs/phases/V1_PHASE_17_DEFINITION.md):
 *
 * - Administrator (a direct role check, mirroring
 *   AuthorizesWorkLogVisibility/AuthorizesLeaveRequestVisibility) sees
 *   and manages every Schedule Entry — unlike Messaging/Notifications,
 *   this is a deliberate, explicit override for this module.
 * - Otherwise, a requester with a linked Staff record may view an entry
 *   they created, one they participate in, or one linked to a Project
 *   their existing Project visibility (`AuthorizesProjectVisibility`'s
 *   exact rule: `projects.view` or current membership) legitimately
 *   allows them to see. A Manager gains no automatic visibility into a
 *   direct report's private (non-Project-linked) entry merely from the
 *   manager relationship.
 * - Mutation authority: the creator (their own entry), a Project Lead of
 *   the entry's linked Project, or Administrator may update/delete;
 *   anyone else who can merely view (a participant) is read-only.
 */
trait AuthorizesScheduleEntryAccess
{
    private function isAdministrator(Request $request): bool
    {
        return $request->user()?->hasRole(Role::ADMINISTRATOR) ?? false;
    }

    private function canViewAllProjects(Request $request): bool
    {
        return $request->user()?->can('projects.view') ?? false;
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

    /**
     * Whether $projectId (a Schedule Entry's linked Project, possibly
     * null) is visible to the requester under the exact same rule
     * AuthorizesProjectVisibility applies to Projects themselves — the
     * "simplest consistent Project authorization model" named by the
     * governing Phase 17 instructions.
     */
    private function canViewViaProject(Request $request, ?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }

        return $this->canViewAllProjects($request) || $this->isMemberOf($request, $projectId);
    }

    /**
     * @param  Builder<ScheduleEntry>  $query
     * @return Builder<ScheduleEntry>
     */
    private function scopeVisibleScheduleEntries(Request $request, Builder $query): Builder
    {
        if ($this->isAdministrator($request)) {
            return $query;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            abort(403, 'You do not have access to view any schedule entries.');
        }

        $visibleProjectIds = $this->canViewAllProjects($request)
            ? null // null means "every project" — handled separately below
            : ProjectMembership::query()->where('staff_id', $staff->id)->pluck('project_id');

        return $query->where(function (Builder $query) use ($staff, $visibleProjectIds) {
            $query->where('creator_staff_id', $staff->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($staff->id));

            if ($visibleProjectIds === null) {
                $query->orWhereNotNull('project_id');
            } elseif ($visibleProjectIds->isNotEmpty()) {
                $query->orWhereIn('project_id', $visibleProjectIds);
            }
        });
    }

    /**
     * The pure, non-aborting predicate behind authorizeView() — also
     * reused by ScheduleController's aggregation to filter Schedule
     * Entry rows without tearing down the whole multi-source request the
     * way an abort() would.
     */
    private function canView(Request $request, ScheduleEntry $entry): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        if ($entry->creator_staff_id === $staff->id) {
            return true;
        }

        $isParticipant = $entry->relationLoaded('participants')
            ? $entry->participants->contains('id', $staff->id)
            : $entry->participants()->whereKey($staff->id)->exists();

        if ($isParticipant) {
            return true;
        }

        return $this->canViewViaProject($request, $entry->project_id);
    }

    private function authorizeView(Request $request, ScheduleEntry $entry): void
    {
        if ($this->canView($request, $entry)) {
            return;
        }

        // Existence is itself sensitive for a private entry — 404, not
        // 403, mirroring every other ownership-scoped resource in this
        // API (Work Logs, Leave self-service, Notifications, Messaging).
        abort(404);
    }

    /**
     * Returns true for full manage authority (update/delete), false for
     * read-only (a mere participant). Aborts (404 — existence is
     * sensitive) if the requester cannot view the entry at all.
     */
    private function authorizeManage(Request $request, ScheduleEntry $entry): bool
    {
        $this->authorizeView($request, $entry);

        if ($this->isAdministrator($request)) {
            return true;
        }

        $staff = $request->user()?->staff;

        if ($staff !== null && $entry->creator_staff_id === $staff->id) {
            return true;
        }

        if ($this->isProjectLeadOf($request, $entry->project_id)) {
            return true;
        }

        return false;
    }
}
