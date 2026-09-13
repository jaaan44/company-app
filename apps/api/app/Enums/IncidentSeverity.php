<?php

namespace App\Enums;

/**
 * Closed severity scale for an Incident Report (Phase 19). Defaults to
 * `Medium` (see IncidentReport::booted()) — deliberately no automatic
 * severity/risk scoring, per the governing Phase 19 instructions.
 */
enum IncidentSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
