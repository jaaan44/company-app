<?php

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopens a rejected Service Report for correction (rejected -> draft).
 * Authority (creator or Administrator) is resolved in
 * ServiceReportController::returnToDraft(), not here.
 */
class ReturnServiceReportToDraftRequest extends FormRequest
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
