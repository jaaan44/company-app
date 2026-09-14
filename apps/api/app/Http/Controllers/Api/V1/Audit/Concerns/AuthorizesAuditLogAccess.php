<?php

namespace App\Http\Controllers\Api\V1\Audit\Concerns;

use App\Models\Role;
use Illuminate\Http\Request;

/**
 * Administrator-only, resolved entirely in-controller via a direct
 * `hasRole()` check (Phase 21, DEC-044) — mirroring the same established,
 * no-new-permission shape Schedule Entries/Service Reports/Incident
 * Reports already use for their own "Administrator (a direct hasRole()
 * check) sees/manages everything" clause (05_SECURITY_MODEL.md). No
 * `audit-logs.view`/`.export` permission was introduced: Manager and
 * Staff have no route to this data at all, not even a narrower grant —
 * unlike every other supervisory surface in this codebase, there is no
 * Manager-of-direct-reports tier here.
 */
trait AuthorizesAuditLogAccess
{
    private function authorizeAdministrator(Request $request): void
    {
        if (! $request->user()?->hasRole(Role::ADMINISTRATOR)) {
            abort(403, 'Only an Administrator may access the Audit Log.');
        }
    }
}
