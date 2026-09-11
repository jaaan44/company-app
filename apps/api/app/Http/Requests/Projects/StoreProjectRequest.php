<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProjectRequest extends FormRequest
{
    /**
     * Permission enforcement happens at the route level
     * (`can:projects.manage`), which runs before this request is
     * resolved — mirroring the existing Staff/Clients Form Requests.
     */
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
            'project_code' => ['nullable', 'string', 'max:50', Rule::unique('projects', 'project_code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_id' => ['nullable', 'string', Rule::exists('clients', 'public_id')],
            'status' => ['sometimes', new Enum(ProjectStatus::class)],
            'start_date' => ['nullable', 'date'],
            'target_end_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date', 'required_if:status,'.ProjectStatus::Completed->value],
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
     * Deliberately not Laravel `after_or_equal:start_date` rules on
     * target_end_date/completed_date — see UpdateProjectRequest for why a
     * partial update needs the *effective* dates instead. A plain Store
     * request always has the full payload, but the same helper method
     * shape is used for consistency and to keep both requests' behavior
     * identical.
     */
    private function validateDateOrder(Validator $validator): void
    {
        if ($this->filled('start_date') && $this->filled('target_end_date')
            && Carbon::parse($this->input('target_end_date'))->lt(Carbon::parse($this->input('start_date')))) {
            $validator->errors()->add('target_end_date', 'The target end date must be on or after the start date.');
        }

        if ($this->filled('start_date') && $this->filled('completed_date')
            && Carbon::parse($this->input('completed_date'))->lt(Carbon::parse($this->input('start_date')))) {
            $validator->errors()->add('completed_date', 'The completed date must be on or after the start date.');
        }
    }
}
