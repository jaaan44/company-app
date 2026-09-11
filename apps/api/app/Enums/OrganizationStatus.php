<?php

namespace App\Enums;

/**
 * Lifecycle state for organization-structure master data (Phase 6 —
 * Departments/Teams/Positions). Deliberately a simple two-state lifecycle
 * ("in current use" vs "retired") rather than AccountStatus's richer
 * active/suspended/inactive split — a retired org unit's history (past
 * references) is preserved, not deleted, by flipping this instead of
 * removing the row. See docs/03_DATABASE_MODEL.md and DEC-029.
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
