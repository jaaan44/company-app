<?php

namespace App\Http\Controllers\Api\V1\Authorization\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * One shape for a sign-in account in Users and roles (Phase 35C) and
 * account management (Phase 36A). Never includes a password or its hash.
 */
trait PresentsAccounts
{
    /**
     * @return array<string, mixed>
     */
    protected function accountData(User $user, Request $request): array
    {
        $user->loadMissing(['role', 'staff']);

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
            'must_change_password' => $user->must_change_password,
            'role' => $user->role === null ? null : ['name' => $user->role->name, 'label' => $user->role->label],
            'staff' => $user->staff === null ? null : ['public_id' => $user->staff->public_id, 'display_name' => $user->staff->displayName()],
            'is_me' => $user->is($request->user()),
        ];
    }
}
