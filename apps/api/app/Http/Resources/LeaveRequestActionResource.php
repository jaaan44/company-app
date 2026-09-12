<?php

namespace App\Http\Resources;

use App\Models\LeaveRequestAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single Leave Request approval-history entry (Phase 13). `acted_by`
 * is the acting User's linked Staff record in minimal shape, or `null`
 * if the actor has no linked Staff (e.g. the seeded local
 * Administrator) — mirrors WorkLogResource's `created_by` pattern.
 *
 * @mixin LeaveRequestAction
 */
class LeaveRequestActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actorStaff = $this->actor?->staff;

        return [
            'action' => $this->action,
            'acted_by' => $actorStaff === null ? null : [
                'public_id' => $actorStaff->public_id,
                'employee_number' => $actorStaff->employee_number,
                'display_name' => $actorStaff->displayName(),
            ],
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
