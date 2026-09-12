<?php

namespace App\Enums;

/**
 * Lifecycle state for a Task (Phase 11 — Tasks). The smallest practical
 * lifecycle for real company work — one mechanism only, no soft deletes,
 * no configurable workflow/Kanban engine, no restricted transition graph
 * (any status may move to any other status; see docs/phases/
 * V1_PHASE_11_DEFINITION.md).
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Whether this status represents a Task that is no longer active
     * (Completed or Cancelled).
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
