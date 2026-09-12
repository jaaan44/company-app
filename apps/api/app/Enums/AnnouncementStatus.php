<?php

namespace App\Enums;

/**
 * Lifecycle state for an Announcement (Phase 14). Three states, no
 * separate/overlapping `inactive`/`expired` concept — this column is the
 * single source of truth for whether an announcement is currently live.
 * Transitions are enforced by explicit action endpoints (publish/archive),
 * never a generic status PATCH — see docs/phases/V1_PHASE_14_DEFINITION.md.
 */
enum AnnouncementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
