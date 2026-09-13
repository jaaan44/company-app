<?php

namespace App\Http\Requests\IncidentReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Transitions `resolved` -> `closed` — the final state. Authority
 * (assigned investigator, reporter's current Manager, or Administrator)
 * and the "must currently be resolved" state check are both resolved in
 * IncidentReportController::close(), not here.
 */
class CloseIncidentReportRequest extends FormRequest
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
