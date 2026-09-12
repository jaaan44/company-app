<?php

namespace App\Http\Resources;

use App\Models\ProjectMilestone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Project Milestone shape (Phase 17). No internal numeric id
 * anywhere; the Project is a minimal nested shape, never the full
 * underlying record.
 *
 * @mixin ProjectMilestone
 */
class ProjectMilestoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status->value,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'public_id' => $this->project->public_id,
                'name' => $this->project->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
