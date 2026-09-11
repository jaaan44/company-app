<?php

namespace App\Http\Resources;

use App\Models\StaffOperationalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single operational-status history entry (Phase 9). Always returned
 * within a known Staff's own collection (self or a specific staff member),
 * so no staff reference is nested — nothing here ever exposes an internal
 * numeric id.
 *
 * @mixin StaffOperationalStatus
 */
class OperationalStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
