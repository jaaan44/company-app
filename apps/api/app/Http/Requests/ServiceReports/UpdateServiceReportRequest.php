<?php

namespace App\Http\Requests\ServiceReports;

use App\Http\Requests\ServiceReports\Concerns\ResolvesServiceReportReferences;
use App\Models\ServiceReport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits a Service Report's content while it is still a `draft`
 * (ServiceReportController checks editability before applying this) — a
 * draft's Client/Project/Task remain correctable up until submission, not
 * fixed at creation (docs/phases/V1_PHASE_18_DEFINITION.md's Editing and
 * Immutability). `creator_staff_id` and `status` are the only fields
 * excluded here: the primary performer is immutable at every status
 * (never just while a draft), and status changes go only through the
 * explicit workflow action endpoints (submit/review/reject/return-to-draft),
 * never this generic update.
 *
 * Any supplied Client/Project/Task change is validated against the exact
 * same relational-coherence rule creation uses
 * (ResolvesServiceReportReferences::validateClientProjectTaskCoherenceForUpdate()),
 * evaluated against the *effective* combination — a changed field's new
 * value, or the existing report's current value for any field left
 * untouched — so a Client change can never silently leave an
 * incompatible Project/Task in place.
 */
class UpdateServiceReportRequest extends FormRequest
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
            'client_id' => ['sometimes', 'string', Rule::exists('clients', 'public_id')],
            'project_id' => ['sometimes', 'nullable', 'string', Rule::exists('projects', 'public_id')],
            'task_id' => ['sometimes', 'nullable', 'string', Rule::exists('tasks', 'public_id')],

            'service_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'work_performed' => ['sometimes', 'string', 'max:10000'],
            'findings' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'recommendations' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'follow_up_actions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'site_representative_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'participant_staff_ids' => ['sometimes', 'array'],
            'participant_staff_ids.*' => ['string', 'distinct', Rule::exists('staff', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var ServiceReport $report */
            $report = $this->route('serviceReport');

            $this->validateClientProjectTaskCoherenceForUpdate($validator, $report);
        });
    }
}
