<?php

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejects a submitted Service Report, returning it to its creator for
 * correction. A reason is required — mirrors
 * RejectLeaveRequestRequest's identical precedent (Phase 13). Reject
 * authority is resolved in ServiceReportController::reject(), not here.
 */
class RejectServiceReportRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
