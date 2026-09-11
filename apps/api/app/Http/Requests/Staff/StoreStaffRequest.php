<?php

namespace App\Http\Requests\Staff;

use App\Enums\StaffStatus;
use App\Http\Requests\Staff\Concerns\ResolvesStaffReferences;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreStaffRequest extends FormRequest
{
    use ResolvesStaffReferences;

    /**
     * Permission enforcement happens at the route level
     * (`can:staff.manage`), which runs before this request is resolved —
     * mirroring the existing Organization Structure Form Requests.
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
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('staff', 'employee_number')],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255', Rule::unique('staff', 'company_email')],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', new Enum(StaffStatus::class)],
            'hire_date' => ['nullable', 'date'],
            'separation_date' => ['nullable', 'date', 'required_if:status,'.StaffStatus::Separated->value],
            'department_id' => ['nullable', 'string', Rule::exists('departments', 'public_id')],
            'team_id' => ['nullable', 'string', Rule::exists('teams', 'public_id')],
            'position_id' => ['nullable', 'string', Rule::exists('positions', 'public_id')],
            'manager_id' => ['nullable', 'string', Rule::exists('staff', 'public_id')],
            'user_id' => ['nullable', 'string', Rule::exists('users', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTeamDepartmentConsistency($validator);
            $this->validateDateOrder($validator);
            $this->validateUserNotAlreadyLinked($validator);
        });
    }

    /**
     * A team that belongs to a department can't be paired with a
     * different, explicitly supplied department on the same staff record
     * (docs/phases/V1_PHASE_07_DEFINITION.md). When department_id isn't
     * supplied at all, StaffController derives it from the team instead —
     * nothing to validate here.
     */
    private function validateTeamDepartmentConsistency(Validator $validator): void
    {
        if (! $this->filled('department_id')) {
            return;
        }

        $teamId = $this->resolveTeamId();

        if ($teamId === null) {
            return;
        }

        $team = Team::query()->find($teamId);

        if ($team === null || $team->department_id === null) {
            return;
        }

        if ($team->department_id !== $this->resolveDepartmentId()) {
            $validator->errors()->add('department_id', 'The selected department does not match the assigned team\'s department.');
        }
    }

    /**
     * Deliberately not a Laravel `after_or_equal:hire_date` rule — that
     * compares against the request's *own* `hire_date` field, which a
     * partial update (UpdateStaffRequest) may not include at all. Both
     * requests instead compare the actual effective dates.
     */
    private function validateDateOrder(Validator $validator): void
    {
        if (! $this->filled('hire_date') || ! $this->filled('separation_date')) {
            return;
        }

        if (Carbon::parse($this->input('separation_date'))->lt(Carbon::parse($this->input('hire_date')))) {
            $validator->errors()->add('separation_date', 'The separation date must be on or after the hire date.');
        }
    }

    /**
     * A user account may be linked to at most one staff record
     * (docs/phases/V1_PHASE_07_DEFINITION.md). This can't be a plain
     * `Rule::unique('staff', 'user_id')` on the submitted field, because
     * the submitted value is the user's public_id (a ULID string) while
     * `staff.user_id` stores the internal numeric id — this checks the
     * resolved id instead.
     */
    private function validateUserNotAlreadyLinked(Validator $validator): void
    {
        $userId = $this->resolveUserId();

        if ($userId === null) {
            return;
        }

        if (Staff::query()->where('user_id', $userId)->exists()) {
            $validator->errors()->add('user_id', 'This user account is already linked to another staff member.');
        }
    }
}
