<?php

namespace App\Http\Resources;

use App\Models\IncidentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Incident Report shape (Phase 19). No internal numeric id anywhere;
 * Client/Project/Task/reporter/assigned_to/participants/created_by are
 * minimal nested shapes, mirroring ServiceReportResource's identical
 * conventions (Phase 18). `history` is the full append-only workflow/
 * assignment-history log (IncidentReportActionResource), oldest first.
 * `attachments` is metadata only — the actual bytes are fetched via the
 * dedicated download endpoint, never inlined here.
 *
 * @mixin IncidentReport
 */
class IncidentReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'public_id' => $this->client->public_id,
                'name' => $this->client->name,
            ]),
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'public_id' => $this->project->public_id,
                'name' => $this->project->name,
            ]),
            'task' => $this->whenLoaded('task', fn () => $this->task === null ? null : [
                'public_id' => $this->task->public_id,
                'title' => $this->task->title,
            ]),
            'reporter' => $this->whenLoaded('reporter', fn () => [
                'public_id' => $this->reporter->public_id,
                'employee_number' => $this->reporter->employee_number,
                'display_name' => $this->reporter->displayName(),
            ]),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo === null ? null : [
                'public_id' => $this->assignedTo->public_id,
                'employee_number' => $this->assignedTo->employee_number,
                'display_name' => $this->assignedTo->displayName(),
            ]),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($staff) => [
                'public_id' => $staff->public_id,
                'employee_number' => $staff->employee_number,
                'display_name' => $staff->displayName(),
            ])),
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'location' => $this->location,
            'incident_type' => $this->incident_type,
            'severity' => $this->severity,
            'description' => $this->description,
            'immediate_action_taken' => $this->immediate_action_taken,
            'root_cause' => $this->root_cause,
            'corrective_action' => $this->corrective_action,
            'preventive_action' => $this->preventive_action,
            'follow_up_actions' => $this->follow_up_actions,
            'resolution' => $this->resolution,
            'people_involved' => $this->people_involved,
            'witness_notes' => $this->witness_notes,
            'status' => $this->status,
            'history' => IncidentReportActionResource::collection($this->whenLoaded('actions')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_by' => $this->whenLoaded('createdByUser', function () {
                $creatorStaff = $this->createdByUser?->staff;

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
