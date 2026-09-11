<?php

namespace App\Http\Requests\StaffOperations;

use App\Enums\OperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a new location check-in (POST /api/v1/me/check-ins). Always
 * self-service — the checking-in staff member is always the authenticated
 * user's own linked Staff record, derived server-side, never accepted from
 * the request body.
 */
class StoreCheckInRequest extends FormRequest
{
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
            // Required together — a check-in fundamentally records where
            // the staff member currently is.
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            // Device-reported GPS accuracy, in meters — no anti-spoofing
            // verification is attempted (out of scope for V1).
            'accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'location_label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],

            // Optional operational-status snapshot captured with the
            // check-in — informational only, does not also update the
            // staff member's separate ongoing operational status.
            'status' => ['nullable', new Enum(OperationalStatus::class)],
        ];
    }
}
