<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Project shape (Phase 10). Deliberately does not nest the full
 * membership roster (avoids a large nested graph on every response) —
 * only a `members_count`; the full roster is fetched via
 * `/api/v1/projects/{public_id}/members`.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->staff;

        return [
            'public_id' => $this->public_id,
            'project_code' => $this->project_code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'target_end_date' => $this->target_end_date?->toDateString(),
            'completed_date' => $this->completed_date?->toDateString(),
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'public_id' => $this->client->public_id,
                'name' => $this->client->name,
            ]),
            'members_count' => $this->whenCounted('memberships'),
            // The requester's own membership role on this project, if
            // any — a small, genuinely useful convenience (docs/phases/
            // V1_PHASE_10_DEFINITION.md), not the full roster.
            'my_role' => $staff === null
                ? null
                : $this->memberships()->where('staff_id', $staff->id)->value('role'),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
