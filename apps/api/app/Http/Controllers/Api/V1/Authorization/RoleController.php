<?php

namespace App\Http\Controllers\Api\V1\Authorization;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Roles & permissions (Phase 35C, DEC-065): what each role holds, and an
 * Administrator changing what Manager or Staff hold. Behind
 * `can:authorization.manage`, which only Administrator holds and which can
 * never be granted (PermissionCatalog::LOCKED), so only an Administrator
 * can change permissions. Administrator itself is never edited: it has
 * every permission through Gate::before (DEC-028).
 */
class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(): JsonResponse
    {
        $labels = Permission::query()->pluck('label', 'name');
        $roles = Role::query()->with('permissions')->whereIn('name', [Role::ADMINISTRATOR, ...PermissionCatalog::EDITABLE_ROLES])->get()
            ->sortBy(fn (Role $role) => array_search($role->name, [Role::ADMINISTRATOR, ...PermissionCatalog::EDITABLE_ROLES], true))
            ->values();

        return response()->json([
            'data' => $roles->map(fn (Role $role) => $this->roleData($role))->all(),
            'meta' => [
                'modules' => collect(PermissionCatalog::MODULES)->map(fn (array $module, string $title) => [
                    'title' => $title,
                    'permissions' => collect([$module['view'], ...$module['writes']])->map(fn (string $name) => [
                        'name' => $name,
                        'label' => $labels[$name] ?? $name,
                        'requires' => PermissionCatalog::requiredView($name),
                    ])->all(),
                    'note' => $module['note'],
                ])->values()->all(),
                'locked' => collect(PermissionCatalog::LOCKED)->map(fn (string $name) => ['name' => $name, 'label' => $labels[$name] ?? $name])->all(),
                'administrator_only' => PermissionCatalog::ADMINISTRATOR_ONLY,
                'defaults' => PermissionCatalog::DEFAULTS,
            ],
        ]);
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        if (! in_array($role->name, PermissionCatalog::EDITABLE_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'The Administrator role always has every permission and can\'t be changed.']);
        }

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::grantable())],
        ], [
            'permissions.*.in' => 'This permission can\'t be given to this role.',
        ]);
        $names = $data['permissions'];

        foreach ($names as $name) {
            $view = PermissionCatalog::requiredView($name);
            if ($view !== null && ! in_array($view, $names, true)) {
                $labels = Permission::query()->whereIn('name', [$name, $view])->pluck('label', 'name');
                throw ValidationException::withMessages([
                    'permissions' => "“{$labels[$name]}” also needs “{$labels[$view]}”.",
                ]);
            }
        }

        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->permissions()->sync(Permission::query()->whereIn('name', $names)->pluck('id'));
        $after = $role->permissions()->pluck('name')->sort()->values()->all();

        if ($before !== $after) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::ROLE_PERMISSIONS_UPDATED,
                'role',
                $role->name,
                ['permissions'],
                ['permissions' => $before, 'removed' => array_values(array_diff($before, $after))],
                ['permissions' => $after, 'added' => array_values(array_diff($after, $before))],
            );
        }

        return response()->json(['data' => $this->roleData($role->load('permissions'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function roleData(Role $role): array
    {
        $editable = in_array($role->name, PermissionCatalog::EDITABLE_ROLES, true);

        return [
            'name' => $role->name,
            'label' => $role->label,
            'editable' => $editable,
            'permissions' => $editable
                ? array_values(array_intersect(PermissionCatalog::grantable(), $role->permissions->pluck('name')->all()))
                : [...PermissionCatalog::grantable(), ...PermissionCatalog::LOCKED],
        ];
    }
}
