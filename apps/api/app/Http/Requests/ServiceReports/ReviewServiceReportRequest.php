<?php

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marks a submitted Service Report `reviewed` — the final successful
 * state for V1 (no separate "approved"/"completed" distinction). Review
 * authority (the creator's current Manager, the linked Project's Project
 * Lead, or Administrator) is resolved in
 * ServiceReportController::review(), not here.
 */
class ReviewServiceReportRequest extends FormRequest
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
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
