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

class UpdateStaffRequest extends FormRequest
{
    use ResolvesStaffReferences;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Staff $staff */
        $staff = $this->route('staff');

        $status = $this->filled('status') ? $this->input('status') : $staff->status->value;

        return [
            'employee_number' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('staff', 'employee_number')->ignore($staff->id)],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255', Rule::unique('staff', 'company_email')->ignore($staff->id)],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', new Enum(StaffStatus::class)],
            'hire_date' => ['nullable', 'date'],
            'separation_date' => ['nullable', 'date', Rule::requiredIf($status === StaffStatus::Separated->value)],
            'department_id' => ['sometimes', 'nullable', 'string', Rule::exists('departments', 'public_id')],
            'team_id' => ['sometimes', 'nullable', 'string', Rule::exists('teams', 'public_id')],
            'position_id' => ['sometimes', 'nullable', 'string', Rule::exists('positions', 'public_id')],
            'manager_id' => ['sometimes', 'nullable', 'string', Rule::exists('staff', 'public_id')],
            'user_id' => ['sometimes', 'nullable', 'string', Rule::exists('users', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTeamDepartmentConsistency($validator);
            $this->validateManager($validator);
            $this->validateDateOrder($validator);
            $this->validateUserNotAlreadyLinked($validator);
        });
    }

    private function validateTeamDepartmentConsistency(Validator $validator): void
    {
        if (! $this->has('department_id')) {
            return;
        }

        /** @var Staff $staff */
        $staff = $this->route('staff');

        $teamId = $this->has('team_id') ? $this->resolveTeamId() : $staff->team_id;

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
     * A staff member cannot be their own manager, and assigning a manager
     * whose own chain would loop back to this staff member (a reporting
     * cycle) is rejected — see Staff::wouldCreateCycleWith() for the
     * bounded, non-general-purpose check this relies on.
     */
    private function validateManager(Validator $validator): void
    {
        if (! $this->has('manager_id')) {
            return;
        }

        $managerId = $this->resolveManagerId();

        if ($managerId === null) {
            return;
        }

        /** @var Staff $staff */
        $staff = $this->route('staff');

        if ($managerId === $staff->id) {
            $validator->errors()->add('manager_id', 'A staff member cannot be their own manager.');

            return;
        }

        if ($staff->wouldCreateCycleWith($managerId)) {
            $validator->errors()->add('manager_id', 'This assignment would create a circular reporting relationship.');
        }
    }

    /**
     * Compares the *effective* hire/separation dates — whichever this
     * request supplies, falling back to the existing record's value for
     * whichever it doesn't — rather than a plain `after_or_equal:hire_date`
     * rule, which only ever looks at the request's own `hire_date` field
     * and would wrongly ignore the persisted one on a partial update.
     */
    private function validateDateOrder(Validator $validator): void
    {
        /** @var Staff $staff */
        $staff = $this->route('staff');

        $hireDate = $this->filled('hire_date')
            ? $this->input('hire_date')
            : $staff->hire_date?->toDateString();

        $separationDate = $this->filled('separation_date')
            ? $this->input('separation_date')
            : $staff->separation_date?->toDateString();

        if ($hireDate === null || $separationDate === null) {
            return;
        }

        if (Carbon::parse($separationDate)->lt(Carbon::parse($hireDate))) {
            $validator->errors()->add('separation_date', 'The separation date must be on or after the hire date.');
        }
    }

    /**
     * See StoreStaffRequest::validateUserNotAlreadyLinked() — the same
     * check, ignoring this staff record itself.
     */
    private function validateUserNotAlreadyLinked(Validator $validator): void
    {
        if (! $this->has('user_id')) {
            return;
        }

        $userId = $this->resolveUserId();

        if ($userId === null) {
            return;
        }

        /** @var Staff $staff */
        $staff = $this->route('staff');

        if (Staff::query()->where('user_id', $userId)->where('id', '!=', $staff->id)->exists()) {
            $validator->errors()->add('user_id', 'This user account is already linked to another staff member.');
        }
    }
}
