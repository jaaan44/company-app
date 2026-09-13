<?php

namespace App\Http\Requests\IncidentReports;

use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Http\Requests\IncidentReports\Concerns\ResolvesIncidentReportReferences;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Creates an Incident Report (Phase 19). Structural/coherence validation
 * only — reporter eligibility and Administrator-on-behalf authority are
 * resolved in IncidentReportController::store(), mirroring
 * StoreServiceReportRequest's identical "Form Request validates shape,
 * controller resolves authority" split (Phase 18). `assigned_to_staff_id`
 * is deliberately never accepted here — assignment authority (the
 * reporter's current Manager, or Administrator) is distinct from
 * reporting authority, so assignment always goes through the dedicated
 * /assign endpoint after creation (docs/phases/V1_PHASE_19_DEFINITION.md's
 * Assignment). Investigation/resolution fields are deliberately not
 * required here — only `description` is (docs/phases/
 * V1_PHASE_19_DEFINITION.md's Narrative Fields).
 */
class StoreIncidentReportRequest extends FormRequest
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
            'client_id' => ['nullable', 'string', Rule::exists('clients', 'public_id')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'public_id')],
            'task_id' => ['nullable', 'string', Rule::exists('tasks', 'public_id')],

            // Administrator-only — see IncidentReportController::store().
            // A non-Administrator supplying this for anyone but
            // themselves is rejected in the controller, not here.
            'reporter_staff_id' => ['sometimes', 'string', Rule::exists('staff', 'public_id')],

            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['nullable', 'string', 'max:255'],
            'incident_type' => ['required', new Enum(IncidentReportType::class)],
            'severity' => ['sometimes', new Enum(IncidentSeverity::class)],

            'description' => ['required', 'string', 'max:10000'],
            'immediate_action_taken' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'preventive_action' => ['nullable', 'string', 'max:5000'],
            'follow_up_actions' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'people_involved' => ['nullable', 'string', 'max:5000'],
            'witness_notes' => ['nullable', 'string', 'max:5000'],

            'participant_staff_ids' => ['sometimes', 'array'],
            'participant_staff_ids.*' => ['string', 'distinct', Rule::exists('staff', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateClientProjectTaskCoherence($validator);
        });
    }
}
