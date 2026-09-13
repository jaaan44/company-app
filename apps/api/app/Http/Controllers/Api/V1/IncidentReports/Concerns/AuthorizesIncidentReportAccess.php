<?php

namespace App\Http\Controllers\Api\V1\IncidentReports\Concerns;

use App\Enums\IncidentReportStatus;
use App\Models\IncidentReport;
use App\Models\Role;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared by IncidentReportController and IncidentReportAttachmentController
 * (Phase 19). No new permission was introduced — per the governing Phase
 * 19 decisions, visibility/authority is resolved entirely in-controller,
 * deliberately **narrower** than Service Reports' model (DEC-041/DEC-042):
 *
 * - Visible to: the reporter, the assigned investigator, listed
 *   participants, the reporter's *current* direct Manager
 *   (Staff.manager_id, a plain relationship check, never a permission),
 *   and Administrator. Unlike Service Reports, a linked Project's Project
 *   Lead gains **no** automatic visibility here at all — Project Lead
 *   must separately qualify as reporter/assignee/participant/manager-of-
 *   record/Administrator (docs/phases/V1_PHASE_19_DEFINITION.md's
 *   Visibility, DEC-042's explicit rejection of Service Reports'
 *   Project-Lead carve-out for this module).
 * - Assignment authority (assign/reassign, and the bundled assignment a
 *   start-investigation call may carry) belongs only to the reporter's
 *   current Manager or Administrator — never the assignee themselves,
 *   never an unrelated Staff member, never the reporter.
 * - Investigation-management authority (editing content while
 *   `under_investigation`, starting investigation, resolving, closing,
 *   reopening) belongs to the assigned investigator, the reporter's
 *   current Manager, or Administrator.
 * - While `reported`, content-management authority additionally includes
 *   the reporter themselves — once `under_investigation`, the reporter is
 *   view-only unless they separately qualify as assignee/manager/
 *   Administrator.
 * - `resolved`/`closed` are content- and attachment-immutable for
 *   everyone, Administrator included — only `reopen` restores mutability.
 */
trait AuthorizesIncidentReportAccess
{
    private function isAdministrator(Request $request): bool
    {
        return $request->user()?->hasRole(Role::ADMINISTRATOR) ?? false;
    }

    private function isReporter(Request $request, IncidentReport $report): bool
    {
        $staff = $request->user()?->staff;

        return $staff !== null && $report->reporter_staff_id === $staff->id;
    }

    private function isAssignedInvestigator(Request $request, IncidentReport $report): bool
    {
        $staff = $request->user()?->staff;

        return $staff !== null && $report->assigned_to_staff_id !== null && $report->assigned_to_staff_id === $staff->id;
    }

    private function isParticipant(Request $request, IncidentReport $report): bool
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
     * reporter (Staff.manager_id, evaluated live) — mirrors
     * AuthorizesServiceReportAccess::isDirectManagerOfCreator()'s
     * identical shape (Phase 18). No `*.view` permission is checked at
     * all — holding a Manager role grants nothing by itself; only the
     * actual reporting relationship does.
     */
    private function isDirectManagerOfReporter(Request $request, IncidentReport $report): bool
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        $reporterManagerId = $report->relationLoaded('reporter')
            ? $report->reporter?->manager_id
            : Staff::query()->whereKey($report->reporter_staff_id)->value('manager_id');

        return $reporterManagerId === $staff->id;
    }

    /**
     * The pure, non-aborting visibility predicate. Deliberately does
     * NOT reuse a Project-Lead carve-out — DEC-042 rejects Service
     * Reports' equivalent for Incident Reports.
     */
    private function canView(Request $request, IncidentReport $report): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        return $this->isReporter($request, $report)
            || $this->isAssignedInvestigator($request, $report)
            || $this->isParticipant($request, $report)
            || $this->isDirectManagerOfReporter($request, $report);
    }

    private function authorizeView(Request $request, IncidentReport $report): void
    {
        if ($this->canView($request, $report)) {
            return;
        }

        // Existence is itself sensitive — 404, not 403, mirroring every
        // other ownership/membership-scoped resource in this API.
        abort(404);
    }

    /**
     * Whether the requester may assign/reassign an investigator — the
     * reporter's current Manager, or Administrator. Never the assignee
     * themselves, never an unrelated Staff member, and never the
     * reporter (docs/phases/V1_PHASE_19_DEFINITION.md's Assignment
     * Authority).
     */
    private function canManageAssignment(Request $request, IncidentReport $report): bool
    {
        return $this->isAdministrator($request) || $this->isDirectManagerOfReporter($request, $report);
    }

    /**
     * Whether the requester may manage this report's investigation —
     * the assigned investigator, the reporter's current Manager, or
     * Administrator. Used for start-investigation/resolve/close/reopen,
     * and for content editing while `under_investigation`.
     */
    private function canManageInvestigation(Request $request, IncidentReport $report): bool
    {
        return $this->isAdministrator($request)
            || $this->isAssignedInvestigator($request, $report)
            || $this->isDirectManagerOfReporter($request, $report);
    }

    /**
     * Whether the requester may edit this report's own content right
     * now, ignoring the resolved/closed immutability gate itself (the
     * controller checks status->isContentMutable() separately as a 409,
     * mirroring ServiceReportController's authorizeManageDraft()-then-
     * isEditable() ordering, Phase 18) — this method only ever answers
     * "if this report *were* mutable, would this actor have authority."
     * While `reported`, the reporter themselves additionally qualifies;
     * while `under_investigation` (or beyond), only
     * canManageInvestigation() does.
     */
    private function canManageContent(Request $request, IncidentReport $report): bool
    {
        if ($this->isAdministrator($request)) {
            return true;
        }

        if ($report->status === IncidentReportStatus::Reported) {
            return $this->isReporter($request, $report) || $this->canManageInvestigation($request, $report);
        }

        return $this->canManageInvestigation($request, $report);
    }

    /**
     * A total stranger (cannot view at all) gets 404. A participant, or
     * the reporter during `under_investigation` without separately
     * qualifying, gets 403 — mirrors
     * AuthorizesServiceReportAccess::authorizeManageDraft()'s identical
     * view-then-authorize shape.
     */
    private function authorizeManageContent(Request $request, IncidentReport $report): void
    {
        $this->authorizeView($request, $report);

        if ($this->canManageContent($request, $report)) {
            return;
        }

        abort(403, 'You do not have permission to modify this incident report.');
    }

    /**
     * @param  Builder<IncidentReport>  $query
     * @return Builder<IncidentReport>
     */
    private function scopeVisibleIncidentReports(Request $request, Builder $query): Builder
    {
        if ($this->isAdministrator($request)) {
            return $query;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            abort(403, 'You do not have access to view any incident reports.');
        }

        return $query->where(function (Builder $query) use ($staff) {
            $query->where('reporter_staff_id', $staff->id)
                ->orWhere('assigned_to_staff_id', $staff->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($staff->id))
                ->orWhereHas('reporter', fn (Builder $q) => $q->where('manager_id', $staff->id));
        });
    }
}
