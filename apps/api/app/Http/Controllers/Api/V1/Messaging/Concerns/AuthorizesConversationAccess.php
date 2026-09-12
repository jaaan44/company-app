<?php

namespace App\Http\Controllers\Api\V1\Messaging\Concerns;

use App\Models\Conversation;
use App\Models\Staff;
use Illuminate\Http\Request;

/**
 * Shared by every Messaging controller. Messaging privacy
 * (05_SECURITY_MODEL.md) is membership-only, not permission-based: there
 * is no `messages.view`/`messages.manage` permission, so Administrator's
 * usual Gate::before "sees everything" override never applies here —
 * this trait never calls $user->can(...), mirroring exactly how
 * NotificationController's ownership check keeps Administrator out.
 * A conversation the requester isn't currently a member of is reported
 * as 404, not 403 (its existence is itself sensitive), mirroring every
 * other ownership-scoped resource in this API.
 */
trait AuthorizesConversationAccess
{
    private function resolveAuthenticatedStaff(Request $request): Staff
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            abort(403, 'No staff record is linked to this account.');
        }

        return $staff;
    }

    private function authorizeMembership(Staff $staff, Conversation $conversation): void
    {
        if (! $conversation->members()->where('staff_id', $staff->id)->exists()) {
            abort(404);
        }
    }
}
