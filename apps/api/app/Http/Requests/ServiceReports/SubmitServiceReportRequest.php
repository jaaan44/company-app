<?php

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submits a draft (or resubmits a rejected-then-reopened) Service Report
 * for review. Authority (creator or Administrator) is resolved in
 * ServiceReportController::submit(), not here.
 */
class SubmitServiceReportRequest extends FormRequest
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
