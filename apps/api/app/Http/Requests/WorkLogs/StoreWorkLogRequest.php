<?php

namespace App\Http\Requests\WorkLogs;

use App\Http\Requests\WorkLogs\Concerns\ResolvesWorkLogReferences;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrator-entered Work Log, naming another Staff member as
 * performer (docs/phases/V1_PHASE_12_DEFINITION.md — Administrative /
 * Supervisory Logging). Authorization (work-logs.manage) is enforced by
 * route middleware, not here. Deliberately does NOT re-validate
 * self-service eligibility (active staff / current membership) — an
 * Administrator is trusted to backfill/correct a historical record for a
 * Staff member who may since have left a Project or become inactive; only
 * existence and Task/Project consistency are enforced.
 */
class StoreWorkLogRequest extends FormRequest
{
    use ResolvesWorkLogReferences;

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
            'staff_id' => ['required', 'string', Rule::exists('staff', 'public_id')],
            'task_id' => ['nullable', 'string', Rule::exists('tasks', 'public_id')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'public_id')],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateAtLeastOneReference($validator);
        });
    }
}
