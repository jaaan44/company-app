<?php

namespace App\Enums;

/**
 * Lifecycle state for a Contact (Phase 8 — Clients & Contacts). A
 * lightweight two-state lifecycle so a contact who has left their client
 * organization can be preserved (not deleted) while being excluded from
 * "who do I currently contact here" views — deliberately not a richer
 * multi-state lifecycle like `StaffStatus`, since a Contact has no
 * separation-date/employment-history concept to track. See
 * docs/phases/V1_PHASE_08_DEFINITION.md.
 */
enum ContactStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
