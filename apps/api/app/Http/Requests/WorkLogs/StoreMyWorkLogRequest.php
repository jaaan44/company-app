<?php

namespace App\Http\Requests\WorkLogs;

use App\Http\Requests\WorkLogs\Concerns\ResolvesWorkLogReferences;
use App\Http\Requests\WorkLogs\Concerns\ValidatesSelfServiceEligibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-service Work Log creation (Phase 12) — the performer is always the
 * authenticated User's own linked Staff record (resolved in
 * MyWorkLogController via RequiresLinkedStaff), never a client-supplied
 * staff_id. Reuses Phase 9's secure self-service precedent.
 */
class StoreMyWorkLogRequest extends FormRequest
{
    use ResolvesWorkLogReferences;
    use ValidatesSelfServiceEligibility;

    /**
     * Requiring a linked Staff record (and that it is active) is enforced
     * by the controller/eligibility validation below, not here — mirroring
     * every other Form Request in this codebase.
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

            $performer = $this->user()->staff;

            if ($performer !== null) {
                $this->validateSelfServiceEligibility($validator, $performer);
            }
        });
    }
}
