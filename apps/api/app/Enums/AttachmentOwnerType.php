<?php

namespace App\Enums;

/**
 * The closed discriminator for the shared `attachments` table (Phase 18
 * — Service Reports, DEC-041) — a typed, non-polymorphic reference
 * (mirroring `NotificationSourceType`'s precedent, DEC-017/DEC-038)
 * rather than storing a raw PHP model class name as a Laravel
 * `attachable_type` value. Unlike `NotificationSourceType`, this
 * discriminator is paired with a genuine, real foreign key column per
 * owner type on `attachments` (`service_report_id`/`incident_report_id`)
 * — never a bare polymorphic id with no database-enforced referential
 * integrity. See docs/phases/V1_PHASE_18_DEFINITION.md's Attachment
 * Architecture.
 *
 * **Phase 19 (DEC-042) adds `IncidentReport`** — the concrete next
 * consumer anticipated since Phase 18 — via a purely additive migration
 * (a new nullable `incident_report_id` column on `attachments`, plus
 * `service_report_id` itself widened to nullable since a row now owns
 * exactly one of the two FKs). No existing Service Report attachment row
 * or behavior is altered.
 */
enum AttachmentOwnerType: string
{
    case ServiceReport = 'service_report';
    case IncidentReport = 'incident_report';
}
