<?php

namespace App\Http\Controllers\Api\V1\Authorization;

use App\Http\Controllers\Api\V1\Authorization\Concerns\PresentsAccounts;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Database\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Users and roles (Phase 35C, DEC-065 P-5): every sign-in account with its
 * role, and an Administrator changing an account's role. Behind
 * `can:authorization.manage`. Two guards: you can't change your own role,
 * and the last Administrator can't be given another role.
 */
class UserRoleController extends Controller
{
    use PresentsAccounts;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $q = trim((string) $request->input('q', ''));

        $page = User::query()->with(['role', 'staff'])
            ->when($q !== '', fn ($query) => Like::anyContains($query, ['name', 'email'], $q))
            ->orderBy('name')->orderBy('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $user) => $this->accountData($user, $request))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'roles' => Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->get(['name', 'label'])
                    ->sortBy(fn (Role $role) => array_search($role->name, [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF], true))
                    ->values()->map(fn (Role $role) => ['name' => $role->name, 'label' => $role->label])->all(),
            ],
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in([Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])],
        ]);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['role' => 'You can\'t change your own role.']);
        }

        $before = $user->role?->name;
        $role = Role::query()->where('name', $data['role'])->firstOrFail();

        if ($before === Role::ADMINISTRATOR && $role->name !== Role::ADMINISTRATOR
            && User::query()->whereRelation('role', 'name', Role::ADMINISTRATOR)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'There must always be at least one Administrator.']);
        }

        if ($before !== $role->name) {
            $user->role()->associate($role)->save();
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::USER_ROLE_CHANGED,
                'user',
                $user->public_id,
                ['role'],
                ['role' => $before],
                ['role' => $role->name],
            );
        }

        return response()->json(['data' => $this->accountData($user->load('role'), $request)]);
    }
}
