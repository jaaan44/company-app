<?php

namespace App\Enums;

/**
 * A single historical event in a Service Report's workflow (Phase 18 —
 * Service Reports, `service_report_actions`). Append-only (DEC-010) —
 * mirrors `LeaveRequestActionType`'s precedent (Phase 13). `Resubmitted`
 * is deliberately distinct from `Submitted`: the first submission of a
 * report's life is always `Submitted`; a later submission after a
 * `ReturnedToDraft` is `Resubmitted` — see
 * ServiceReportController::submit().
 */
enum ServiceReportActionType: string
{
    case Submitted = 'submitted';
    case Rejected = 'rejected';
    case ReturnedToDraft = 'returned_to_draft';
    case Resubmitted = 'resubmitted';
    case Reviewed = 'reviewed';
}
