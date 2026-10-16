<?php

namespace App\Http\Controllers\Api\V1\Authorization;

use App\Enums\AccountStatus;
use App\Http\Controllers\Api\V1\Authorization\Concerns\PresentsAccounts;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Auth\Passwords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in accounts (Phase 36A, DEC-066 M-3…M-5), behind
 * `can:authorization.manage` (Administrators only).
 *
 * - Create: a temporary password is generated, returned **once** in this
 *   response (`temporary_password`) and stored only as a hash. The
 *   account must choose its own password before anything else.
 * - Reset password: the same, and every existing sign-in ends.
 * - Status: suspend or reactivate. Suspending ends every sign-in.
 *
 * You can't reset or suspend your own account. Only an active
 * Administrator gets here, so one always remains. Every change is
 * audited; a password never is.
 */
class AccountController extends Controller
{
    use PresentsAccounts;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::in([Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])],
            'staff_id' => ['sometimes', 'nullable', 'string', Rule::exists('staff', 'public_id')],
        ], [
            'email.unique' => 'An account with this email already exists.',
        ]);

        $staff = isset($data['staff_id']) ? Staff::query()->where('public_id', $data['staff_id'])->firstOrFail() : null;
        if ($staff?->user_id !== null) {
            throw ValidationException::withMessages(['staff_id' => 'This staff member already has a sign-in account.']);
        }

        $temporary = Passwords::temporary();
        $role = Role::query()->where('name', $data['role'])->firstOrFail();

        $user = DB::transaction(function () use ($data, $temporary, $role, $staff): User {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $temporary]);
            $user->status = AccountStatus::Active;
            $user->must_change_password = true;
            $user->role()->associate($role);
            $user->save();
            $staff?->forceFill(['user_id' => $user->id])->save();

            return $user;
        });

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::USER_CREATED,
            'user',
            $user->public_id,
            ['name', 'email', 'role', 'staff'],
            [],
            ['name' => $user->name, 'email' => $user->email, 'role' => $role->name, 'staff' => $staff?->public_id],
        );

        return response()->json(['data' => $this->accountData($user, $request) + ['temporary_password' => $temporary]], 201);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'To change your own password, use Change password on your profile.']);
        }

        $temporary = Passwords::temporary();
        $user->password = $temporary;
        $user->must_change_password = true;
        $user->save();
        Passwords::endSignIns($user);

        $this->auditLogger->recordForRequest($request, AuditActions::USER_PASSWORD_RESET, 'user', $user->public_id);

        return response()->json(['data' => $this->accountData($user, $request) + ['temporary_password' => $temporary]]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([AccountStatus::Active->value, AccountStatus::Suspended->value])],
        ]);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['status' => 'You can\'t suspend or reactivate your own account.']);
        }

        $before = $user->status;
        $after = AccountStatus::from($data['status']);

        if ($before !== $after) {
            $user->status = $after;
            $user->save();
            if ($after !== AccountStatus::Active) {
                Passwords::endSignIns($user);
            }
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::USER_STATUS_CHANGED,
                'user',
                $user->public_id,
                ['status'],
                ['status' => $before->value],
                ['status' => $after->value],
            );
        }

        return response()->json(['data' => $this->accountData($user, $request)]);
    }
}
