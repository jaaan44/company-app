<?php

namespace App\Enums;

/**
 * Lifecycle state for a Service Report (Phase 18 — Service Reports).
 * Exactly four states — a deliberately smaller machine than a generic
 * draft/submitted/approved/completed chain: `reviewed` is the single
 * final successful state (there is no separate "approved" vs "completed"
 * distinction, since a Service Report documents work already performed,
 * unlike a Task which continues after approval). See
 * docs/phases/V1_PHASE_18_DEFINITION.md's Workflow section and DEC-041.
 *
 * Normal flow: draft -> submitted -> reviewed, or submitted -> rejected
 * -> draft (reopened for correction) -> submitted -> reviewed. Every
 * transition is performed by an explicit action endpoint
 * (ServiceReportController::submit()/review()/reject()/returnToDraft()),
 * never a generic status PATCH.
 */
enum ServiceReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Rejected = 'rejected';

    /**
     * Whether report content/attachments may be freely mutated. Only a
     * `draft` is editable — `submitted` and `reviewed` are content-
     * immutable, and `rejected` must first be returned to `draft` before
     * any content or attachment change is allowed (docs/phases/
     * V1_PHASE_18_DEFINITION.md's Editing and Immutability).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
