<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Leave Request shape (Phase 13). No internal numeric id anywhere;
 * Staff/Leave Type/creator are minimal nested shapes. `history` is the
 * full append-only approval-history log (LeaveRequestActionResource),
 * oldest first.
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
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
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'public_id' => $this->leaveType->public_id,
                'name' => $this->leaveType->name,
                'code' => $this->leaveType->code,
                'is_paid' => $this->leaveType->is_paid,
            ]),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'total_days' => $this->total_days,
            'reason' => $this->reason,
            'status' => $this->status,
            'history' => LeaveRequestActionResource::collection($this->whenLoaded('actions')),
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
