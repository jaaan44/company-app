<?php

namespace App\Enums;

/**
 * The closed set of business events that actually produce a Notification
 * (Phase 15). Deliberately narrow — only events an authorized producer
 * actually wires up belong here; never a raw PHP class/event name exposed
 * to the API. See docs/phases/V1_PHASE_15_DEFINITION.md's Event
 * Integration section for why this list isn't longer.
 *
 * Extended in Phase 16 with exactly one new case (`MessageReceived`) —
 * the roadmap's own "notification of new messages" dependency line, the
 * second real producer after Announcement publish.
 */
enum NotificationType: string
{
    case AnnouncementPublished = 'announcement_published';
    case MessageReceived = 'message_received';
}
