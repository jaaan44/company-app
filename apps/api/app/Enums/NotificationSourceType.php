<?php

namespace App\Enums;

/**
 * The closed set of domain record types a Notification may point back to
 * (Phase 15) — a typed alternative to a generic polymorphic relation (see
 * docs/phases/V1_PHASE_15_DEFINITION.md's Source / Context Reference
 * section). Grows only alongside NotificationType, one entry per module
 * that actually produces notifications.
 */
enum NotificationSourceType: string
{
    case Announcement = 'announcement';
}
