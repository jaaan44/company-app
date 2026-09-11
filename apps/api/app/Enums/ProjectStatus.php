<?php

namespace App\Enums;

/**
 * Lifecycle state for a Project (Phase 10 — Projects & Project Membership).
 * The smallest practical lifecycle for realistic project flow — one
 * mechanism only, no soft deletes or separate archival flag alongside it.
 * See docs/phases/V1_PHASE_10_DEFINITION.md.
 */
enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Whether this status represents a project that is no longer ongoing
     * (Completed or Cancelled). Reopening (changing away from a terminal
     * status) is permitted — this is not a one-way gate.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
