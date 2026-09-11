<?php

namespace App\Http\Resources;

use App\Models\StaffCheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single location check-in (Phase 9). Always returned within a known
 * Staff's own collection (self or a specific staff member), so no staff
 * reference is nested — only `public_id` ever identifies the check-in
 * itself, never an internal numeric id.
 *
 * @mixin StaffCheckIn
 */
class CheckInResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'accuracy_meters' => $this->accuracy_meters,
            'location_label' => $this->location_label,
            'note' => $this->note,
            'status' => $this->status?->value,
            'checked_in_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
