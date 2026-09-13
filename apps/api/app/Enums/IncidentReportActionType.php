<?php

namespace App\Enums;

/**
 * A single historical event in an Incident Report's workflow (Phase 19,
 * `incident_report_actions`). Append-only (DEC-010) — mirrors
 * ServiceReportActionType's structural precedent (Phase 18), but with an
 * event set matching the investigation-oriented lifecycle
 * (IncidentReportStatus) rather than Service Reports' draft/review shape.
 * `Reported` is recorded once, at creation; `Assigned`/`Reassigned` are
 * recorded by their own dedicated endpoints (and, when bundled into
 * starting an investigation, by that endpoint); `Reopened` is never a
 * persisted status value — only this action-history event plus a status
 * of `under_investigation`.
 */
enum IncidentReportActionType: string
{
    case Reported = 'reported';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case InvestigationStarted = 'investigation_started';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';
}
