<?php

namespace App\Http\Requests\IncidentReports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reassigns an already-assigned Incident Report to a different
 * investigator. Authority (reporter's current Manager, or Administrator)
 * and the "must currently be assigned" state check are both resolved in
 * IncidentReportController::reassign(), not here.
 */
class ReassignIncidentReportRequest extends FormRequest
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
            'assigned_to_staff_id' => ['required', 'string', Rule::exists('staff', 'public_id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
