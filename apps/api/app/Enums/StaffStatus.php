<?php

namespace App\Enums;

/**
 * Employment lifecycle for a Staff record (Phase 7). Deliberately distinct
 * from both `AccountStatus` (login/account state on `User`) and the future
 * Phase 9 operational/current status (available, on leave, in the field,
 * off duty) — this is "are they currently an employee," not "are they
 * logged in" or "what are they doing right now." Three states, mirroring
 * `AccountStatus`'s established V1 pattern rather than
 * `OrganizationStatus`'s simpler two-state master-data lifecycle, because
 * "temporarily inactive" and "no longer employed" are meaningfully
 * different for a person record. See DEC-030.
 */
enum StaffStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Separated = 'separated';

    /**
     * Whether this status represents someone still nominally employed
     * (Active or Inactive), as opposed to Separated.
     */
    public function isEmployed(): bool
    {
        return $this !== self::Separated;
    }
}
