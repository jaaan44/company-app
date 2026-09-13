<?php

namespace App\Http\Controllers\Api\V1\ServiceReports\Concerns;

use App\Enums\ProjectMembershipRole;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\ServiceReport;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared by ServiceReportController and ServiceReportAttachmentController
 * (Phase 18). No new permission was introduced — per the governing Phase
 * 18 instructions' explicit rejection of a broad `service-reports.view`
 * permission that would grant every Manager company-wide visibility.
 * Visibility/authority is resolved entirely in-controller, mirroring
 * Tasks' AuthorizesTaskAccess (DEC-034) and Schedule Entries'
 * AuthorizesScheduleEntryAccess (DEC-040) in shape:
 *
 * - Administrator (a direct role check, the simplest convention
 *   consistent with every other module) sees and manages everything.
 * - A Service Report is visible to: its creator, its listed participants,
 *   the creator's *current* direct Manager (Staff.manager_id — a plain
 *   relationship check, not a permission, mirroring
 *   AuthorizesLeaveRequestVisibility's isDirectManagerOf() shape but
 *   without any `*.view` permission gate at all), and the linked
 *   Project's Project Lead (reusing the established ProjectMembership
 *   check, mirroring AuthorizesTaskAccess/AuthorizesScheduleEntryAccess).
 * - Review authority (submit/review/reject decisions) belongs to the
 *   creator's current direct Manager, the linked Project's Project Lead,
 *   or Administrator — never the creator themselves, and never a mere
 *   participant.
 * - Draft management authority (edit content, delete, mutate attachments)
 *   belongs only to the creator or Administrator — participants gain
 *   visibility but never workflow-management authority (docs/phases/
 *   V1_PHASE_18_DEFINITION.md's Participants/Visibility).
 */
trait AuthorizesServiceReportAccess
{
    private function isAdministrator(Request $request): bool
    {
        return $request->user()?->hasRole(Role::ADMINISTRATOR) ?? false;
    }

    private function isCreator(Request $request, ServiceReport $report): bool
    {
        $staff = $request->user()?->staff;

        return $staff !== null && $report->creator_staff_id === $staff->id;
    }

    private function isParticipant(Request $request, ServiceReport $report): bool
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        return $report->relationLoaded('participants')
            ? $report->participants->contains('id', $staff->id)
            : $report->participants()->whereKey($staff->id)->exists();
    }

    /**
     * Whether the requester is the *current* direct Manager of $report's
     * creator (Staff.manager_id, evaluated live — mirrors
     * AuthorizesLeaveRequestVisibility's isDirectManagerOf()). No
     * `*.view` permission is checked at all — holding a Manager role
     * grants nothing by itself; only the actual reporting relationship
     * does.
     */
    private function isDirectManagerOfCreator(Request $request, ServiceReport $report): bool
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        $creatorManagerId = $report->relationLoaded('creator')
            ? $report->creator?->manager_id
            : Staff::query()->whereKey($report->creator_staff_id)->value('manager_id');

        return $creatorManagerId === $staff->id;
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

    /**
     * The pure, non-aborting visibility predicate.
     */
    private function canView(Request $request, ServiceReport $report): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        return $this->isCreator($request, $report)
            || $this->isParticipant($request, $report)
            || $this->isDirectManagerOfCreator($request, $report)
            || $this->isProjectLeadOf($request, $report->project_id);
    }

    private function authorizeView(Request $request, ServiceReport $report): void
    {
        if ($this->canView($request, $report)) {
            return;
        }

        // Existence is itself sensitive — 404, not 403, mirroring every
        // other ownership/membership-scoped resource in this API (Work
        // Logs, Schedule Entries, Messaging, Notifications).
        abort(404);
    }

    /**
     * Whether the requester may decide (review/reject) a *submitted*
     * report. Never the creator themselves, even defensively (mirrors
     * LeaveRequestController::authorizeDecisionAuthority()'s identical
     * self-decision guard).
     */
    private function canReview(Request $request, ServiceReport $report): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        if ($this->isCreator($request, $report)) {
            return false;
        }

        return $this->isDirectManagerOfCreator($request, $report)
            || $this->isProjectLeadOf($request, $report->project_id);
    }

    /**
     * A total stranger (cannot view the report at all) gets 404 —
     * existence is itself sensitive, mirroring authorizeManageDraft()'s
     * identical view-then-authorize shape. A participant or the creator
     * themselves (who CAN view but lacks review authority) gets 403.
     */
    private function authorizeReview(Request $request, ServiceReport $report): void
    {
        $this->authorizeView($request, $report);

        if ($this->canReview($request, $report)) {
            return;
        }

        abort(403, 'You do not have authority to review this service report.');
    }

    /**
     * Whether the requester may mutate this report's own content, delete
     * it, return it to draft, or mutate its attachments. Only the
     * creator or Administrator — participants and reviewers never gain
     * this authority.
     */
    private function canManageDraft(Request $request, ServiceReport $report): bool
    {
        return $this->isAdministrator($request) || $this->isCreator($request, $report);
    }

    private function authorizeManageDraft(Request $request, ServiceReport $report): void
    {
        $this->authorizeView($request, $report);

        if ($this->canManageDraft($request, $report)) {
            return;
        }

        abort(403, 'You do not have permission to modify this service report.');
    }

    /**
     * @param  Builder<ServiceReport>  $query
     * @return Builder<ServiceReport>
     */
    private function scopeVisibleServiceReports(Request $request, Builder $query): Builder
    {
        if ($this->isAdministrator($request)) {
            return $query;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            abort(403, 'You do not have access to view any service reports.');
        }

        $leadProjectIds = ProjectMembership::query()
            ->where('staff_id', $staff->id)
            ->where('role', ProjectMembershipRole::ProjectLead)
            ->pluck('project_id');

        return $query->where(function (Builder $query) use ($staff, $leadProjectIds) {
            $query->where('creator_staff_id', $staff->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($staff->id))
                ->orWhereHas('creator', fn (Builder $q) => $q->where('manager_id', $staff->id));

            if ($leadProjectIds->isNotEmpty()) {
                $query->orWhereIn('project_id', $leadProjectIds);
            }
        });
    }
}
