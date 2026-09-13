<?php

namespace App\Enums;

/**
 * The closed discriminator for the shared `attachments` table (Phase 18
 * — Service Reports, DEC-041) — a typed, non-polymorphic reference
 * (mirroring `NotificationSourceType`'s precedent, DEC-017/DEC-038)
 * rather than storing a raw PHP model class name as a Laravel
 * `attachable_type` value. Unlike `NotificationSourceType`, this
 * discriminator is paired with a genuine, real foreign key column per
 * owner type on `attachments` (`service_report_id` today) — never a bare
 * polymorphic id with no database-enforced referential integrity. See
 * docs/phases/V1_PHASE_18_DEFINITION.md's Attachment Architecture.
 *
 * Contains only `ServiceReport` for now — Phase 19 (Incident Reports),
 * the concrete next consumer, adds its own case and its own nullable
 * `incident_report_id` column via an additive migration when that phase
 * is actually authorized, never pre-added here speculatively.
 */
enum AttachmentOwnerType: string
{
    case ServiceReport = 'service_report';
}
