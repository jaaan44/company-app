<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Deliberately exposes only safe identity information: the ULID
     * `public_id` (never the internal numeric id), name, email, and
     * account status. Never the password hash, remember token, the
     * transitional `is_admin` flag, or other internal detail.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
        ];
    }
}
