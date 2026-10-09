<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My profile (Phase 28 — People: Staff Directory & Profile (Mobile),
 * R-2): `GET /api/v1/me/profile`, the authenticated person's own account
 * identity and their own linked Staff record. See
 * docs/phases/V1_PHASE_28_DEFINITION.md §6.1.
 *
 * **Always the authenticated person's own profile.** The subject comes
 * exclusively from the token — no request parameter is read — so it can
 * never be pointed at another person. No permission is involved: like
 * every `/me/...` surface, reading your own record needs none.
 *
 * **No linked Staff record is required** (the `/me/home` precedent,
 * DEC-052): such a user gets `200` with `staff: null`, never a `403` —
 * the mobile client treats a 403 as "forbidden", not "no profile".
 *
 * `staff` is the existing Phase 7 `StaffResource` with the same eager
 * loads as `StaffController`, so it is field-for-field identical to
 * `GET /api/v1/staff/{public_id}` for the same requester, including its
 * `staff.manage`-only `user` block. No new field is exposed.
 */
class MyProfileController extends Controller
{
    private const STAFF_RELATIONS = ['department', 'team', 'position', 'manager', 'user', 'latestOperationalStatus'];

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('role');

        $staff = $user->staff;
        $staff?->loadMissing(self::STAFF_RELATIONS);

        return response()->json([
            'data' => [
                'user' => [
                    'public_id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->name,
                ],
                'staff' => $staff === null ? null : (new StaffResource($staff))->resolve($request),
            ],
        ]);
    }
}
