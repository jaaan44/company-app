<?php

namespace App\Http\Resources;

use App\Models\ScheduleEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The manually created Schedule Entry shape (Phase 17). No internal
 * numeric id anywhere; creator/participants/Project are minimal nested
 * shapes. `starts_at`/`ends_at` are always full ISO-8601 UTC datetimes
 * regardless of `is_all_day` — see the schedule_entries migration and
 * App\Support\Scheduling\ScheduleEntryTiming for the storage rationale.
 *
 * @mixin ScheduleEntry
 */
class ScheduleEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'activity_type' => $this->activity_type->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'is_all_day' => $this->is_all_day,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'public_id' => $this->project->public_id,
                'name' => $this->project->name,
            ]),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : [
                'public_id' => $this->creator->public_id,
                'employee_number' => $this->creator->employee_number,
                'display_name' => $this->creator->displayName(),
            ]),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($staff) => [
                'public_id' => $staff->public_id,
                'employee_number' => $staff->employee_number,
                'display_name' => $staff->displayName(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
