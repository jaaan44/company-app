<?php

namespace App\Http\Requests\Leave\Concerns;

use App\Models\LeaveType;
use App\Models\Staff;

/**
 * Shared by every Leave Request Form Request (Phase 13). Resolves
 * client-supplied public IDs to internal numeric IDs/models — never
 * trusting/exposing the numeric ID itself.
 */
trait ResolvesLeaveRequestReferences
{
    private function resolveLeaveType(): ?LeaveType
    {
        $publicId = $this->input('leave_type_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return LeaveType::query()->where('public_id', $publicId)->first();
    }

    private function resolveStaffId(): ?int
    {
        $publicId = $this->input('staff_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Staff::query()->where('public_id', $publicId)->value('id');
    }
}
