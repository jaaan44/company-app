<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Staff Directory shape (Phase 7) — deliberately a single resource,
 * not a separate "public directory" vs "admin" resource: every field
 * below (name, contact, department/team/position, manager, status) is
 * exactly what docs/phases/V1_PHASE_07_DEFINITION.md's Staff Directory
 * requirement calls for, and is visible to anyone holding `staff.view`
 * (Administrator/Manager/Staff). The one exception is the linked User
 * account's own identity, which is administrative/management
 * information, not directory information — that's only included for a
 * requester who also holds `staff.manage` (see `user` below);
 * `has_user_account` (a plain boolean) is always safe to expose.
 *
 * @mixin Staff
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'preferred_name' => $this->preferred_name,
            'display_name' => $this->displayName(),
            'company_email' => $this->company_email,
            'company_phone' => $this->company_phone,
            'status' => $this->status->value,
            'hire_date' => $this->hire_date?->toDateString(),
            'separation_date' => $this->separation_date?->toDateString(),
            'department' => $this->whenLoaded('department', fn () => $this->department === null ? null : [
                'public_id' => $this->department->public_id,
                'name' => $this->department->name,
            ]),
            'team' => $this->whenLoaded('team', fn () => $this->team === null ? null : [
                'public_id' => $this->team->public_id,
                'name' => $this->team->name,
            ]),
            'position' => $this->whenLoaded('position', fn () => $this->position === null ? null : [
                'public_id' => $this->position->public_id,
                'title' => $this->position->title,
            ]),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager === null ? null : [
                'public_id' => $this->manager->public_id,
                'display_name' => $this->manager->displayName(),
            ]),
            'has_user_account' => $this->user_id !== null,
            'user' => $this->when(
                $request->user()?->can('staff.manage') ?? false,
                fn () => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                    'public_id' => $this->user->public_id,
                    'email' => $this->user->email,
                    'status' => $this->user->status->value,
                ]),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
