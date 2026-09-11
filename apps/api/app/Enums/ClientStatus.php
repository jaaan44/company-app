<?php

namespace App\Enums;

/**
 * Lifecycle state for a Client (Phase 8 — Clients & Contacts). A two-state
 * lifecycle mirroring `OrganizationStatus` (Departments/Teams/Positions),
 * not `StaffStatus`'s three states — a Client is business-relationship
 * master data, not a person's employment record, so a client relationship
 * is simply current or not. Retiring a client flips this instead of
 * deleting the row, preserving history (contacts, and later modules'
 * references). See docs/phases/V1_PHASE_08_DEFINITION.md.
 */
enum ClientStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
