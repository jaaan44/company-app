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

    public function test_it_creates_the_phase_6_organization_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'organization.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'organization.manage')->exists());
    }

    public function test_manager_and_staff_are_granted_organization_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();

        $this->assertTrue($manager->permissions->contains('name', 'organization.view'));
        $this->assertTrue($staff->permissions->contains('name', 'organization.view'));
        $this->assertFalse($manager->permissions->contains('name', 'organization.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'organization.manage'));
    }

    public function test_it_creates_the_phase_7_staff_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'staff.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'staff.manage')->exists());
    }

    public function test_manager_and_staff_are_granted_staff_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();

        $this->assertTrue($manager->permissions->contains('name', 'staff.view'));
        $this->assertTrue($staff->permissions->contains('name', 'staff.view'));
        $this->assertFalse($manager->permissions->contains('name', 'staff.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'staff.manage'));
    }

    public function test_running_it_twice_does_not_duplicate_rows(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();

        $this->assertSame(3, Role::query()->count());
        $this->assertSame(6, Permission::query()->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'organization.view')->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'staff.view')->count());
    }
}
