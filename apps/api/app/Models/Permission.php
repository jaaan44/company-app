<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single authorization capability (Phase 5 — Roles & Permissions,
 * DEC-028). Named in '<module>.<action>' dot notation (e.g.
 * 'admin.access') — see DEC-004 and docs/05_SECURITY_MODEL.md. Deliberately
 * a small, foundational catalog in this phase; future modules add their
 * own permissions when they're actually built, not speculatively here.
 */
#[Fillable(['name', 'label'])]
class Permission extends Model
{
    use HasFactory;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
