<?php

namespace App\Http\Resources;

use App\Models\ProjectMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Project Membership's shape (Phase 10). No membership `public_id` (it
 * is never independently addressed — see App\Models\ProjectMembership)
 * and no nested Project reference (always fetched within a Project's own
 * nested `/members` endpoint, so it would be redundant).
 *
 * @mixin ProjectMembership
 */
class ProjectMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'staff' => [
                'public_id' => $this->staff->public_id,
                'employee_number' => $this->staff->employee_number,
                'display_name' => $this->staff->displayName(),
            ],
            'role' => $this->role->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
