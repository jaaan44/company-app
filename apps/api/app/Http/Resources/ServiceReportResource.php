<?php

namespace App\Http\Resources;

use App\Models\ServiceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Service Report shape (Phase 18). No internal numeric id anywhere;
 * Client/Project/Task/creator/participants/created_by are minimal nested
 * shapes. `history` is the full append-only workflow-history log
 * (ServiceReportActionResource), oldest first — mirrors
 * LeaveRequestResource's identical `history` field. `attachments` is
 * metadata only — the actual bytes are fetched via the dedicated
 * download endpoint, never inlined here.
 *
 * @mixin ServiceReport
 */
class ServiceReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'client' => $this->whenLoaded('client', fn () => [
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
            'creator' => $this->whenLoaded('creator', fn () => [
                'public_id' => $this->creator->public_id,
                'employee_number' => $this->creator->employee_number,
                'display_name' => $this->creator->displayName(),
            ]),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($staff) => [
                'public_id' => $staff->public_id,
                'employee_number' => $staff->employee_number,
                'display_name' => $staff->displayName(),
            ])),
            'service_date' => $this->service_date->toDateString(),
            'work_performed' => $this->work_performed,
            'findings' => $this->findings,
            'recommendations' => $this->recommendations,
            'follow_up_actions' => $this->follow_up_actions,
            'site_representative_name' => $this->site_representative_name,
            'status' => $this->status,
            'history' => ServiceReportActionResource::collection($this->whenLoaded('actions')),
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
