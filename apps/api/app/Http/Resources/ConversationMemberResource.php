<?php

namespace App\Http\Resources;

use App\Models\ConversationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single roster entry within a Conversation's `members` list — the
 * member's Staff identity only. No internal numeric id.
 *
 * @mixin ConversationMember
 */
class ConversationMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'staff' => [
                'public_id' => $this->staff->public_id,
                'name' => $this->staff->displayName(),
            ],
        ];
    }
}
