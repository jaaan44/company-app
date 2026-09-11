<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Client directory shape (Phase 8). Deliberately does not nest the
 * full Contacts list (avoids a large nested graph on every response) —
 * only a `contacts_count`; the full list is fetched via
 * `/api/v1/contacts?client=<public_id>`.
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'client_code' => $this->client_code,
            'name' => $this->name,
            'status' => $this->status->value,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'state_province' => $this->state_province,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'notes' => $this->notes,
            'contacts_count' => $this->whenCounted('contacts'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
