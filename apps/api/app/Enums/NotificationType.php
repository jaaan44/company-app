<?php

namespace App\Enums;

/**
 * The closed set of business events that actually produce a Notification
 * (Phase 15). Deliberately narrow — only events an authorized producer
 * actually wires up belong here; never a raw PHP class/event name exposed
 * to the API. See docs/phases/V1_PHASE_15_DEFINITION.md's Event
 * Integration section for why this list isn't longer.
 */
enum NotificationType: string
{
    case AnnouncementPublished = 'announcement_published';
}
