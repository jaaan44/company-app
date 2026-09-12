<?php

namespace App\Enums;

/**
 * Lifecycle state for a Leave Type (Phase 13 — Leave Management). A
 * dedicated two-state enum rather than reusing `OrganizationStatus` —
 * Leave Type is HR master data, a distinct domain from organization
 * structure, mirroring `ClientStatus`/`ContactStatus`'s identical
 * reasoning (DEC-031). Deactivating a Leave Type blocks its use in new
 * Leave Requests while every existing reference remains intact (DEC-010).
 */
enum LeaveTypeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
