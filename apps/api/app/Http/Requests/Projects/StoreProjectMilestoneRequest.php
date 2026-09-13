<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectMilestoneStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreProjectMilestoneRequest extends FormRequest
{
    /**
     * Manage authority (Administrator or the Project's Project Lead)
     * depends on the route's Project, so it happens in
     * ProjectMilestoneController — mirroring every other Form Request in
     * this codebase.
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
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date'],
            'status' => ['sometimes', new Enum(ProjectMilestoneStatus::class)],
        ];
    }
}
