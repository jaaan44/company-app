<?php

namespace App\Enums;

/**
 * A Task's relative urgency (Phase 11 — Tasks). A small, fixed enum —
 * no configurable priority catalog. See docs/phases/
 * V1_PHASE_11_DEFINITION.md.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
