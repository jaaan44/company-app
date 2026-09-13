<?php

namespace App\Http\Requests\IncidentReports;

use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Http\Requests\IncidentReports\Concerns\ResolvesIncidentReportReferences;
use App\Models\IncidentReport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Edits an Incident Report's content while it is `reported` or
 * `under_investigation` (IncidentReportController checks editability and
 * actor authority before applying this — see
 * AuthorizesIncidentReportAccess). `reporter_staff_id` is immutable at
 * every status and never accepted here; `assigned_to_staff_id` and
 * `status` are likewise excluded — assignment goes only through the
 * dedicated assign/reassign/start-investigation endpoints (so every
 * change is captured in incident_report_actions) and status only through
 * the explicit workflow action endpoints, never this generic update
 * (docs/phases/V1_PHASE_19_DEFINITION.md's Editing Authority).
 *
 * Any supplied Client/Project/Task change is validated against the same
 * relational-coherence rule creation uses, evaluated against the
 * *effective* combination — mirrors UpdateServiceReportRequest's
 * identical precedent (Phase 18).
 */
class UpdateIncidentReportRequest extends FormRequest
{
    use ResolvesIncidentReportReferences;

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
            'client_id' => ['sometimes', 'nullable', 'string', Rule::exists('clients', 'public_id')],
            'project_id' => ['sometimes', 'nullable', 'string', Rule::exists('projects', 'public_id')],
            'task_id' => ['sometimes', 'nullable', 'string', Rule::exists('tasks', 'public_id')],

            'occurred_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'incident_type' => ['sometimes', new Enum(IncidentReportType::class)],
            'severity' => ['sometimes', new Enum(IncidentSeverity::class)],

            'description' => ['sometimes', 'string', 'max:10000'],
            'immediate_action_taken' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'preventive_action' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'follow_up_actions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'people_involved' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'witness_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'participant_staff_ids' => ['sometimes', 'array'],
            'participant_staff_ids.*' => ['string', 'distinct', Rule::exists('staff', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var IncidentReport $report */
            $report = $this->route('incidentReport');

            $this->validateClientProjectTaskCoherenceForUpdate($validator, $report);
        });
    }
}
