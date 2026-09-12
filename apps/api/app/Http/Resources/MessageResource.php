<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single Message's shape — no internal numeric id, no editable/edited
 * marker (messages are immutable after sending — see the Message model).
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'body' => $this->body,
            'sender' => [
                'public_id' => $this->sender->public_id,
                'name' => $this->sender->displayName(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
