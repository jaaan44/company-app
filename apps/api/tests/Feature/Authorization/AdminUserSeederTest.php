<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The local development Admin account must be provisioned through the
 * Phase 5 role-based authorization model, not the retired `is_admin`
 * flag (CLAUDE.md §15/§18).
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_seeder_assigns_the_administrator_role(): void
    {
        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($user->hasRole(Role::ADMINISTRATOR));
        $this->assertTrue($user->can('admin.access'));
    }

    public function test_admin_user_seeder_is_idempotent(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', 'admin@example.test')->count(),
        );
    }
}
