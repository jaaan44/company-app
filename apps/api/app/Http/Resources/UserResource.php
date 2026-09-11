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
     * `public_id` (never the internal numeric id), name, email, account
     * status, and the user's role *name* (e.g. "administrator" — never
     * the internal numeric `role_id`). Never the password hash, remember
     * token, or other internal detail. `role` is nullable — a user with
     * no role assigned yet exposes `null`, not a fabricated default.
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
            'role' => $this->role?->name,
        ];
    }
}
