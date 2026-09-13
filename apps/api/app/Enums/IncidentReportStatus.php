<?php

namespace App\Enums;

/**
 * Lifecycle state for an Incident Report (Phase 19). An investigation-
 * oriented workflow, deliberately not a copy of Service Reports' draft/
 * submitted/reviewed/rejected shape (DEC-041) — an incident must exist
 * and be visible immediately upon reporting (safety/liability reasons
 * favor prompt reporting over a draft-then-approve gate), then progress
 * through investigation to resolution over time (see
 * docs/phases/V1_PHASE_19_DEFINITION.md's Workflow and DEC-042).
 *
 * Normal flow: reported -> under_investigation -> resolved -> closed.
 * Reopening (resolved -> under_investigation, or closed ->
 * under_investigation) is an explicit `reopen` action — "reopened" is
 * recorded only as an IncidentReportAction event, never persisted as a
 * fifth status value.
 */
enum IncidentReportStatus: string
{
    case Reported = 'reported';
    case UnderInvestigation = 'under_investigation';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * Whether report content/attachments may be mutated at all in this
     * status — true for `reported`/`under_investigation`, false for
     * `resolved`/`closed` (content- and attachment-immutable; only an
     * explicit `reopen` restores mutability). *Who* specifically may
     * mutate within a mutable status is a separate, actor-dependent
     * question resolved by AuthorizesIncidentReportAccess, not this
     * enum.
     */
    public function isContentMutable(): bool
    {
        return match ($this) {
            self::Reported, self::UnderInvestigation => true,
            self::Resolved, self::Closed => false,
        };
    }
}
