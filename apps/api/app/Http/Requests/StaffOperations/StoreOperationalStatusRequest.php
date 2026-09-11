<?php

namespace App\Http\Requests\StaffOperations;

use App\Enums\OperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a new operational-status history entry — used both for
 * self-service (POST /api/v1/me/status) and an Administrator correcting
 * another staff member's status (POST /api/v1/staff/{public_id}/status).
 * The target staff member is never taken from the request body — it's
 * always derived server-side (the authenticated user's own linked Staff
 * record, or the route-bound Staff for the Administrator path).
 */
class StoreOperationalStatusRequest extends FormRequest
{
    /**
     * Permission/domain enforcement happens at the route level
     * (`can:staff-status.manage`) or in the controller (self-service linked
     * Staff check) — mirroring every other Form Request in this codebase.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(OperationalStatus::class)],
        ];
    }
}
