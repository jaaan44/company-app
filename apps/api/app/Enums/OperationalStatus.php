<?php

namespace App\Enums;

/**
 * A Staff member's current operational/work status (Phase 9) — deliberately
 * distinct from `StaffStatus` (Phase 7, employment lifecycle: active/
 * inactive/separated) and from `AccountStatus` (login/account state).
 * Manually set by the staff member (or, as a correction, an
 * Administrator) — never inferred, and never a substitute for leave/
 * attendance concepts. Five states cover ordinary day-to-day operational
 * visibility, including field staff, without building presence
 * infrastructure (no heartbeat/idle-detection/WebSocket presence). See
 * DEC-032.
 */
enum OperationalStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case InMeeting = 'in_meeting';
    case InField = 'in_field';
    case OffDuty = 'off_duty';
}
