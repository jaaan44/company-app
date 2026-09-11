<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Contact's directory shape (Phase 8). Nests only a minimal Client
 * reference (public_id + name) — never the full Client resource.
 *
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'job_title' => $this->job_title,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_primary' => $this->is_primary,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'client' => $this->whenLoaded('client', fn () => [
                'public_id' => $this->client->public_id,
                'name' => $this->client->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
