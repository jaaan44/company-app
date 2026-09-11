<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateProjectRequest extends FormRequest
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
        /** @var Project $project */
        $project = $this->route('project');

        $status = $this->filled('status') ? $this->input('status') : $project->status->value;

        return [
            'project_code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('projects', 'project_code')->ignore($project->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_id' => ['sometimes', 'nullable', 'string', Rule::exists('clients', 'public_id')],
            'status' => ['sometimes', new Enum(ProjectStatus::class)],
            'start_date' => ['nullable', 'date'],
            'target_end_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date', Rule::requiredIf($status === ProjectStatus::Completed->value)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDateOrder($validator);
        });
    }

    /**
     * Compares the *effective* start/target-end/completed dates —
     * whichever this request supplies, falling back to the existing
     * record's value for whichever it doesn't — rather than a plain
     * `after_or_equal:start_date` rule, which only ever looks at the
     * request's own start_date field and would wrongly ignore the
     * persisted one on a partial update (same reasoning as
     * UpdateStaffRequest::validateDateOrder(), Phase 7).
     */
    private function validateDateOrder(Validator $validator): void
    {
        /** @var Project $project */
        $project = $this->route('project');

        $startDate = $this->filled('start_date')
            ? $this->input('start_date')
            : $project->start_date?->toDateString();

        if ($startDate === null) {
            return;
        }

        $targetEndDate = $this->filled('target_end_date')
            ? $this->input('target_end_date')
            : $project->target_end_date?->toDateString();

        if ($targetEndDate !== null && Carbon::parse($targetEndDate)->lt(Carbon::parse($startDate))) {
            $validator->errors()->add('target_end_date', 'The target end date must be on or after the start date.');
        }

        $completedDate = $this->filled('completed_date')
            ? $this->input('completed_date')
            : $project->completed_date?->toDateString();

        if ($completedDate !== null && Carbon::parse($completedDate)->lt(Carbon::parse($startDate))) {
            $validator->errors()->add('completed_date', 'The completed date must be on or after the start date.');
        }
    }
}
