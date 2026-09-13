<?php

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits a Service Report's content while it is still a `draft`
 * (ServiceReportController checks editability before applying this).
 * Deliberately excludes client_id/project_id/task_id/creator_staff_id/
 * status — the Client/Project/Task anchor and the primary performer are
 * immutable after creation (mirroring Work Log's identical
 * staff_id/task_id/project_id immutability, DEC-035): a wrongly-attributed
 * draft is deleted and recreated rather than reassigned, which is safe
 * precisely because only a draft — never a submitted/reviewed/rejected
 * report — can ever be deleted. Status changes go only through the
 * explicit workflow action endpoints (submit/review/reject/return-to-draft),
 * never this generic update.
 */
class UpdateServiceReportRequest extends FormRequest
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
}
