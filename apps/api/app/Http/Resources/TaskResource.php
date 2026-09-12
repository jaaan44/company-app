<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Task shape (Phase 11). No internal numeric id anywhere; Project/
 * assignee/creator are minimal nested shapes, never the full underlying
 * record. `created_by` is only ever a minimal Staff shape (mirroring
 * assignee), never a raw User identity — and only present when the
 * creating User has a linked Staff record; otherwise `null` (docs/
 * phases/V1_PHASE_11_DEFINITION.md — "avoid exposing unnecessary
 * internal account information").
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
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
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'public_id' => $this->project->public_id,
                'name' => $this->project->name,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : [
                'public_id' => $this->assignee->public_id,
                'employee_number' => $this->assignee->employee_number,
                'display_name' => $this->assignee->displayName(),
            ]),
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
