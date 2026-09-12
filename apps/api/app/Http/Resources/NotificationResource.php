<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Notification shape (Phase 15) — no internal numeric id, no
 * recipient_user_id, no raw PHP class name anywhere. `source` is null
 * whenever a notification carries no source reference; otherwise a
 * minimal, bounded `{ type, public_id }` pair — never the source's own
 * content (title/body etc.), which the client fetches through that
 * source's own, separately-authorized endpoint.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'source' => $this->source_type !== null ? [
                'type' => $this->source_type,
                'public_id' => $this->source_public_id,
            ] : null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
