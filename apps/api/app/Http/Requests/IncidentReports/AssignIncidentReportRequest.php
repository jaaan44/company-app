<?php

namespace App\Http\Requests\IncidentReports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigns an investigator to a currently-unassigned Incident Report
 * (docs/phases/V1_PHASE_19_DEFINITION.md's Assignment). Authority
 * (reporter's current Manager, or Administrator) and the "must currently
 * be unassigned" state check are both resolved in
 * IncidentReportController::assign(), not here — mirrors every other
 * workflow action Form Request in this codebase (authority/state live in
 * the controller, shape validation lives here).
 */
class AssignIncidentReportRequest extends FormRequest
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
