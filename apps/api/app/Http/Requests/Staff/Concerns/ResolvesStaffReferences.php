<?php

namespace App\Http\Requests\Staff\Concerns;

use App\Models\Department;
use App\Models\Position;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

/**
 * Shared by Store/UpdateStaffRequest. Clients submit Department/Team/
 * Position/Manager/User references by `public_id` (DEC-017 — internal
 * numeric ids are never accepted from or exposed to clients); these
 * resolve each to the internal id used for storage and cross-field
 * validation. Each returns `null` for a missing/invalid value rather than
 * failing — existence is separately enforced by each request's own
 * `Rule::exists(...)` validation rule.
 */
trait ResolvesStaffReferences
{
    protected function resolveDepartmentId(): ?int
    {
        return $this->resolvePublicId(Department::class, 'department_id');
    }

    protected function resolveTeamId(): ?int
    {
        return $this->resolvePublicId(Team::class, 'team_id');
    }

    protected function resolvePositionId(): ?int
    {
        return $this->resolvePublicId(Position::class, 'position_id');
    }

    protected function resolveManagerId(): ?int
    {
        return $this->resolvePublicId(Staff::class, 'manager_id');
    }

    protected function resolveUserId(): ?int
    {
        return $this->resolvePublicId(User::class, 'user_id');
    }

    /**
     * @param  class-string<Department|Position|Staff|Team|User>  $modelClass
     */
    private function resolvePublicId(string $modelClass, string $field): ?int
    {
        $publicId = $this->input($field);

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
