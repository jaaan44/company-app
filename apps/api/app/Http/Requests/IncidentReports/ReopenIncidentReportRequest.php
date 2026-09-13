<?php

namespace App\Http\Requests\IncidentReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopens a `resolved` or `closed` Incident Report, returning it to
 * `under_investigation` (docs/phases/V1_PHASE_19_DEFINITION.md's Reopen).
 * `reopened` is recorded only as an IncidentReportAction event — there is
 * no persisted "reopened" status value. Authority (assigned investigator,
 * reporter's current Manager, or Administrator) and the "must currently
 * be resolved or closed" state check are both resolved in
 * IncidentReportController::reopen(), not here.
 */
class ReopenIncidentReportRequest extends FormRequest
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
