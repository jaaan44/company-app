<?php

namespace App\Http\Requests\IncidentReports;

use App\Models\IncidentReport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transitions `reported` -> `under_investigation`. An assignee must
 * exist before or as part of entering this state (docs/phases/
 * V1_PHASE_19_DEFINITION.md's Workflow Meaning) — `assigned_to_staff_id`
 * is optional here specifically to support "as part of": if the incident
 * is still unassigned and this request doesn't supply one, validation
 * fails with a field error rather than the controller silently starting
 * an unassigned investigation. When it does supply one, assignment
 * authority (IncidentReportController::startInvestigation()) applies —
 * distinct from mere investigation-management authority.
 */
class StartInvestigationIncidentReportRequest extends FormRequest
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
            'assigned_to_staff_id' => ['sometimes', 'nullable', 'string', Rule::exists('staff', 'public_id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var IncidentReport $report */
            $report = $this->route('incidentReport');

            $suppliesAssignee = $this->filled('assigned_to_staff_id');

            if (! $suppliesAssignee && $report->assigned_to_staff_id === null) {
                $validator->errors()->add('assigned_to_staff_id', 'An assignee is required before this incident report can enter investigation.');
            }
        });
    }
}
