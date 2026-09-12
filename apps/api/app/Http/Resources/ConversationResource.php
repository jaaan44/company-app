<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Conversation's shape — no internal numeric id. `unread_count` is
 * always computed for the authenticated caller's own membership row
 * (never a generic/global count), derived live from ConversationMember::
 * unreadCount(), never a stored/cached column. Requires the `members`
 * relation to already be loaded (every controller action building this
 * resource eager-loads it).
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $callerStaffId = $request->user()?->staff?->id;
        $myMembership = $this->members->firstWhere('staff_id', $callerStaffId);

        return [
            'public_id' => $this->public_id,
            'type' => $this->type,
            'name' => $this->name,
            'project' => $this->when($this->project_id !== null, fn () => [
                'public_id' => $this->project?->public_id,
                'name' => $this->project?->name,
            ]),
            'owner' => $this->when($this->owner_staff_id !== null, fn () => [
                'public_id' => $this->owner?->public_id,
                'name' => $this->owner?->displayName(),
            ]),
            'members' => ConversationMemberResource::collection($this->members),
            'unread_count' => $myMembership?->unreadCount(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
