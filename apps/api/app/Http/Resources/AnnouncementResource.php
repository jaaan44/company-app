<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Announcement shape (Phase 14) — a single resource shared by both the
 * management surface and the employee feed, mirroring `StaffResource`'s
 * "one resource, gate sensitive fields" precedent. No internal numeric ID
 * anywhere; Department/Team/creator/publisher are minimal nested shapes.
 *
 * `acknowledged_at` is only meaningful on `/me/announcements` responses
 * (whether/when the requesting Staff member acknowledged this specific
 * announcement) — it is present only when the controller has eager-loaded
 * `acknowledgements` scoped to that one Staff member; it is absent
 * (not `null`) on the management surface, where "the requesting user" is
 * not a meaningful acknowledger. `acknowledgements_count` is the opposite:
 * a plain accountability count, only exposed to an `announcements.manage`
 * holder (mirrors `ClientResource.contacts_count`) — never a per-staff
 * read-rate breakdown.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'audience_type' => $this->audience_type,
            'departments' => $this->whenLoaded('departments', fn () => $this->departments->map(fn ($department) => [
                'public_id' => $department->public_id,
                'name' => $department->name,
            ])),
            'teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn ($team) => [
                'public_id' => $team->public_id,
                'name' => $team->name,
            ])),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->minimalStaffFor($this->creator)),
            'published_by' => $this->whenLoaded('publisher', fn () => $this->minimalStaffFor($this->publisher)),
            'acknowledged_at' => $this->whenLoaded(
                'acknowledgements',
                fn () => $this->acknowledgements->first()?->created_at?->toIso8601String(),
            ),
            'acknowledgements_count' => $this->when(
                $request->user()?->can('announcements.manage') ?? false,
                fn () => $this->whenCounted('acknowledgements'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function minimalStaffFor(?User $user): ?array
    {
        $staff = $user?->staff;

        if ($staff === null) {
            return null;
        }

        return [
            'public_id' => $staff->public_id,
            'employee_number' => $staff->employee_number,
            'display_name' => $staff->displayName(),
        ];
    }
}
