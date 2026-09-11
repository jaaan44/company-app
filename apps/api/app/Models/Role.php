<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A V1 system role (Phase 5 — Roles & Permissions, DEC-028). A fixed,
 * deliberately small catalog — not a user-manageable resource in this
 * phase. See docs/05_SECURITY_MODEL.md and DEC-028 for the Administrator
 * override behavior.
 */
#[Fillable(['name', 'label'])]
class Role extends Model
{
    use HasFactory;

    public const ADMINISTRATOR = 'administrator';

    public const MANAGER = 'manager';

    public const STAFF = 'staff';

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
