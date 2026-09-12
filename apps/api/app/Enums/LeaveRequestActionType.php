<?php

namespace App\Enums;

/**
 * A single historical event in a Leave Request's approval workflow
 * (Phase 13 — Leave Management, `leave_request_actions`). Append-only —
 * see docs/phases/V1_PHASE_13_DEFINITION.md's Approval History.
 */
enum LeaveRequestActionType: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
