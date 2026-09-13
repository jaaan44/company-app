<?php

namespace App\Enums;

/**
 * Lifecycle state for a Project Milestone (Phase 17 — Scheduler). The
 * smallest useful lifecycle for a target date marker: a milestone is
 * either still ahead (`pending`), has been reached (`completed`), or is
 * no longer relevant (`cancelled`) — deliberately not `TaskStatus`'s
 * larger shape (`in_progress`/`blocked`), since a milestone is a
 * point-in-time target, not ongoing work with its own progress states.
 * No percent-complete, dependency graph, or workflow engine (see
 * docs/phases/V1_PHASE_17_DEFINITION.md).
 */
enum ProjectMilestoneStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
