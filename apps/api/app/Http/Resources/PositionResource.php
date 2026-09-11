<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
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
            'sort_order' => $this->sort_order,
            'department' => $this->whenLoaded('department', fn () => $this->department === null ? null : [
                'public_id' => $this->department->public_id,
                'name' => $this->department->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
