<?php

namespace App\Http\Requests\IncidentReports;

use App\Models\IncidentReport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Transitions `under_investigation` -> `resolved`. Requires a non-empty
 * `resolution` (either already on the record, or supplied here) and at
 * least one of `corrective_action`/`immediate_action_taken` (likewise) —
 * `root_cause` is deliberately never required (docs/phases/
 * V1_PHASE_19_DEFINITION.md's Resolution Requirements). Any of the three
 * narrative fields may be supplied inline with this request as a
 * convenience (persisted alongside the transition) or may already have
 * been set via a prior PUT/PATCH — the *effective* value (this request's,
 * or the existing record's) is what's actually validated, mirroring
 * UpdateServiceReportRequest's identical "effective combination" approach
 * to optional fields (Phase 18).
 */
class ResolveIncidentReportRequest extends FormRequest
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
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'immediate_action_taken' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var IncidentReport $report */
            $report = $this->route('incidentReport');

            $resolution = $this->has('resolution') ? $this->input('resolution') : $report->resolution;

            if (blank($resolution)) {
                $validator->errors()->add('resolution', 'A resolution is required before this incident report can be resolved.');
            }

            $correctiveAction = $this->has('corrective_action') ? $this->input('corrective_action') : $report->corrective_action;
            $immediateAction = $this->has('immediate_action_taken') ? $this->input('immediate_action_taken') : $report->immediate_action_taken;

            if (blank($correctiveAction) && blank($immediateAction)) {
                $validator->errors()->add('corrective_action', 'At least one of corrective_action or immediate_action_taken is required before this incident report can be resolved.');
            }
        });
    }
}
