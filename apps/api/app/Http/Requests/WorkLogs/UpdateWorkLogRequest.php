<?php

namespace App\Http\Requests\WorkLogs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Administrator correction of any Work Log (work-logs.manage, enforced by
 * route middleware). Only work_date/duration_minutes/description may be
 * changed — staff_id/task_id/project_id remain immutable even for an
 * Administrator; a wrongly-attributed Work Log is deleted and recreated
 * instead (docs/phases/V1_PHASE_12_DEFINITION.md's Editing Rules).
 */
class UpdateWorkLogRequest extends FormRequest
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
            'staff_id' => ['prohibited'],
            'task_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'work_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
        ];
    }
}
