<?php

namespace App\Http\Controllers\Api\V1\StaffOperations\Concerns;

use App\Models\Staff;
use Illuminate\Http\Request;

/**
 * Shared by the self-service ("me") operational-status and check-in
 * controller actions. Phase 7 deliberately keeps Staff↔User optional in
 * both directions (DEC-030) — self-service status/check-in actions require
 * the authenticated User to actually have a linked Staff record, and this
 * is where that domain rule (not a new authentication/authorization
 * mechanism) is enforced.
 */
trait RequiresLinkedStaff
{
    private function resolveAuthenticatedStaff(Request $request): Staff
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            abort(403, 'No staff record is linked to this account.');
        }

        return $staff;
    }
}
