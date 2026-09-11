<?php

namespace App\Enums;

/**
 * A Staff member's responsibility within one specific Project (Phase 10),
 * deliberately distinct from the global Administrator/Manager/Staff
 * application role (`App\Models\Role`) — a Staff-role user may still be a
 * Project Lead on a particular Project. Deliberately small: no
 * configurable role catalog. Multiple co-leads are permitted; no
 * single-project-lead enforcement is built (see
 * docs/phases/V1_PHASE_10_DEFINITION.md).
 */
enum ProjectMembershipRole: string
{
    case ProjectLead = 'project_lead';
    case Member = 'member';
}
