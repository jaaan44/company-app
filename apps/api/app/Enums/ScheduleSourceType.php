<?php

namespace App\Enums;

/**
 * The closed discriminator for an item in the unified Scheduler
 * projection (`GET /api/v1/schedule`, Phase 17). Mirrors
 * `NotificationSourceType`'s typed, non-polymorphic shape (DEC-017/
 * DEC-038) — a small fixed set naming exactly the source modules Phase
 * 17 aggregates, extended by one case only when a future phase adds a
 * genuinely new aggregated source. Not to be confused with
 * `ScheduleEntryActivityType`, which describes the *kind of activity* a
 * manually created Schedule Entry represents — a `schedule_entry` row's
 * `activity_type` is a separate, nested detail, never conflated with
 * this discriminator (see docs/phases/V1_PHASE_17_DEFINITION.md).
 *
 * The unified projection is computed at read time from each source
 * module's own system-of-record table — no calendar rows are copied or
 * cached into a generic table for any of these ("derive, don't cache",
 * DEC-032/036/039).
 */
enum ScheduleSourceType: string
{
    case ScheduleEntry = 'schedule_entry';
    case Task = 'task';
    case Leave = 'leave';
    case ProjectMilestone = 'project_milestone';
}
