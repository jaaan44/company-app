<?php

namespace App\Enums;

/**
 * Lifecycle state for a Leave Request (Phase 13 — Leave Management).
 * Four states, no `draft` — a request is always immediately `pending`
 * once created. Transitions are enforced by explicit action endpoints
 * (approve/reject/cancel), never a generic status PATCH — see
 * docs/phases/V1_PHASE_13_DEFINITION.md's Leave Request Lifecycle.
 */
enum LeaveRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Whether this status is final — no further transition is ever
     * permitted (Rejected/Cancelled are immutable).
     */
    public function isFinal(): bool
    {
        return $this === self::Rejected || $this === self::Cancelled;
    }
}
