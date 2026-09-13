<?php

namespace App\Http\Requests\ServiceReports;

use App\Http\Requests\ServiceReports\Concerns\ResolvesServiceReportReferences;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a Service Report (Phase 18). Structural/coherence validation
 * only — creator eligibility (active Staff / current Project membership
 * / Task assignment) and Administrator-on-behalf authority are resolved
 * in ServiceReportController::store(), mirroring TaskController's
 * identical "Form Request validates shape, controller resolves
 * authority" split for a single flat endpoint serving multiple actor
 * tiers (docs/phases/V1_PHASE_18_DEFINITION.md).
 */
class StoreServiceReportRequest extends FormRequest
{
    use ResolvesServiceReportReferences;

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
            'client_id' => ['required', 'string', Rule::exists('clients', 'public_id')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'public_id')],
            'task_id' => ['nullable', 'string', Rule::exists('tasks', 'public_id')],

            // Administrator-only — see ServiceReportController::store().
            // A non-Administrator supplying this for anyone but
            // themselves is rejected in the controller, not here (the
            // controller is where "who is the requester" is known).
            'creator_staff_id' => ['sometimes', 'string', Rule::exists('staff', 'public_id')],

            'service_date' => ['required', 'date', 'before_or_equal:today'],
            'work_performed' => ['required', 'string', 'max:10000'],
            'findings' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'follow_up_actions' => ['nullable', 'string', 'max:5000'],
            'site_representative_name' => ['nullable', 'string', 'max:255'],

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
