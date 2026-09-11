<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            // Minimal nested shape — never the internal numeric
            // department id (DEC-017). Null when the team has no
            // department yet.
            'department' => $this->whenLoaded('department', fn () => $this->department === null ? null : [
                'public_id' => $this->department->public_id,
                'name' => $this->department->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
