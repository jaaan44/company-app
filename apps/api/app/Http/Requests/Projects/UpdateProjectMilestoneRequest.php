<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectMilestoneStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateProjectMilestoneRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'due_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', new Enum(ProjectMilestoneStatus::class)],
        ];
    }
}
