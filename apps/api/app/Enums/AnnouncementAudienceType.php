<?php

namespace App\Enums;

/**
 * Targeting shape for an Announcement (Phase 14). A single scope type per
 * announcement, never both at once — `Scoped` targets one or more
 * Departments/Teams with union (OR) semantics, never an intersection. See
 * docs/phases/V1_PHASE_14_DEFINITION.md's Audience / Targeting section.
 */
enum AnnouncementAudienceType: string
{
    case CompanyWide = 'company_wide';
    case Scoped = 'scoped';
}
