<?php

namespace App\Http\Requests\Organization\Concerns;

use App\Models\Department;

/**
 * Shared by Team/Position store/update requests. Clients submit a
 * department by its `public_id` (DEC-017 — internal numeric ids are never
 * accepted from or exposed to clients); this resolves that to the
 * internal id used for storage and for department-scoped uniqueness
 * checks. Existence is separately enforced by each request's own
 * `Rule::exists('departments', 'public_id')` validation rule — this
 * returns `null` for a missing/invalid value rather than failing, so it
 * can be safely called while building the rules() array itself.
 */
trait ResolvesDepartmentId
{
    protected function resolveDepartmentId(): ?int
    {
        $publicId = $this->input('department_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Department::query()->where('public_id', $publicId)->value('id');
    }
}
