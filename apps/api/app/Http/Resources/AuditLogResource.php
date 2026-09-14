<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The general Audit Log entry shape (Phase 21, DEC-044) — no internal
 * numeric id anywhere (neither this row's own `id` nor the actor's), the
 * actor is exposed only as its public identity, and `before`/`after` are
 * exactly the curated, allowlisted values each integration site wrote —
 * never a wholesale snapshot, since none was ever stored.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'actor' => $this->actor !== null ? [
                'public_id' => $this->actor->public_id,
                'name' => $this->actor->name,
            ] : null,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_public_id' => $this->entity_public_id,
            'changed_fields' => $this->changed_fields,
            'before' => $this->before,
            'after' => $this->after,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'source' => $this->source,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
