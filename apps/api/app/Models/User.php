<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property AccountStatus $status
 * @property int|null $role_id
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * Whether this account is currently allowed to authenticate / retain
     * an authenticated session or token. Centralizes the account-state
     * check so it isn't scattered across controllers (CLAUDE.md §7).
     */
    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Whether this user holds the given system role (Role::ADMINISTRATOR,
     * Role::MANAGER, Role::STAFF). A user with no role assigned never
     * matches (default-deny).
     */
    public function hasRole(string $name): bool
    {
        return $this->role?->name === $name;
    }

    /**
     * Whether this user's role grants the given permission. A user with
     * no role, or whose role grants nothing matching, is denied — this is
     * the single, centralized permission check every authorization
     * surface (Gate, middleware, Policy) ultimately relies on; see
     * App\Providers\AppServiceProvider::boot() and DEC-028.
     */
    public function hasPermission(string $name): bool
    {
        return $this->role?->permissions->contains('name', $name) ?? false;
    }
}
