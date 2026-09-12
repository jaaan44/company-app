<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Enums\ConversationType;
use App\Http\Controllers\Api\V1\Messaging\Concerns\AuthorizesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\StoreConversationMemberRequest;
use App\Http\Resources\ConversationMemberResource;
use App\Models\Conversation;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group conversation membership management (Phase 16). Direct
 * conversation membership is fixed (always exactly two, set at
 * creation); project conversation membership is controlled only through
 * Project Membership (see ProjectMembershipController) — neither
 * supports these endpoints. Only a group's single owner may add or
 * remove another member; any member (owner included) may remove
 * themselves (leave), subject to the owner invariant below. No
 * multiple-administrators, moderator role, ownership transfer, or role
 * hierarchy concept exists.
 */
class ConversationMemberController extends Controller
{
    use AuthorizesConversationAccess;

    public function store(StoreConversationMemberRequest $request, Conversation $conversation): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeMembership($staff, $conversation);
        $this->ensureGroup($conversation);

        if ($conversation->owner_staff_id !== $staff->id) {
            abort(403, 'Only the group owner may add members.');
        }

        $targetStaffId = Staff::query()->where('public_id', $request->validated('staff_id'))->value('id');

        $member = $conversation->members()->firstOrCreate(['staff_id' => $targetStaffId]);

        return (new ConversationMemberResource($member->load('staff')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Removes a member from a group conversation — either the owner
     * removing someone else, or a member (owner included) leaving
     * themselves. The owner invariant: the owner may never leave/be
     * removed while any other member remains, so a group is never left
     * ownerless with members still in it. Once the owner is the sole
     * remaining member, leaving is permitted (the group simply becomes
     * empty — a harmless, terminal state; there is no conversation
     * hard-delete endpoint).
     */
    public function destroy(Request $request, Conversation $conversation, Staff $staff): JsonResponse
    {
        $requesterStaff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeMembership($requesterStaff, $conversation);
        $this->ensureGroup($conversation);

        $targetMembership = $conversation->members()->where('staff_id', $staff->id)->first();

        if ($targetMembership === null) {
            abort(404);
        }

        $isSelf = $staff->id === $requesterStaff->id;
        $isOwnerActing = $requesterStaff->id === $conversation->owner_staff_id;

        if (! $isSelf && ! $isOwnerActing) {
            abort(403, 'Only the group owner may remove another member.');
        }

        $targetIsOwner = $staff->id === $conversation->owner_staff_id;

        if ($targetIsOwner && $conversation->members()->where('staff_id', '!=', $staff->id)->exists()) {
            abort(409, 'The group owner cannot leave or be removed while other members remain.');
        }

        $targetMembership->delete();

        return response()->json(status: 204);
    }

    private function ensureGroup(Conversation $conversation): void
    {
        if ($conversation->type === ConversationType::Direct) {
            abort(409, 'Direct conversation membership is fixed.');
        }

        if ($conversation->type === ConversationType::Project) {
            abort(409, 'Project conversation membership is managed only through Project Membership.');
        }
    }
}
