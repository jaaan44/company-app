<?php

namespace App\Http\Resources;

use App\Models\WorkLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Work Log shape (Phase 12). No internal numeric id anywhere; Staff/
 * Task/Project/creator are minimal nested shapes, never the full
 * underlying record. `created_by` mirrors TaskResource's own pattern —
 * only present when the creating User has a linked Staff record.
 *
 * @mixin WorkLog
 */
class WorkLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'staff' => $this->whenLoaded('staff', fn () => [
                'public_id' => $this->staff->public_id,
                'employee_number' => $this->staff->employee_number,
                'display_name' => $this->staff->displayName(),
            ]),
            'task' => $this->whenLoaded('task', fn () => $this->task === null ? null : [
                'public_id' => $this->task->public_id,
                'title' => $this->task->title,
            ]),
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'public_id' => $this->project->public_id,
                'name' => $this->project->name,
            ]),
            'work_date' => $this->work_date?->toDateString(),
            'duration_minutes' => $this->duration_minutes,
            'description' => $this->description,
            'created_by' => $this->whenLoaded('creator', function () {
                $creatorStaff = $this->creator?->staff;

                return $creatorStaff === null ? null : [
                    'public_id' => $creatorStaff->public_id,
                    'employee_number' => $creatorStaff->employee_number,
                    'display_name' => $creatorStaff->displayName(),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
