<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foundational role/permission catalog data must be created
 * deterministically and idempotently (CLAUDE.md §15).
 */
class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_v1_role_catalog(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF],
            Role::query()->orderBy('id')->pluck('name')->all(),
        );
    }

    public function test_it_creates_the_foundational_permission_catalog(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'admin.access')->exists());
    }

    public function test_running_it_twice_does_not_duplicate_rows(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(3, Role::query()->count());
        $this->assertSame(2, Permission::query()->count());
    }
}
