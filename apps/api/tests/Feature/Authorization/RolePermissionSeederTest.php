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

    public function test_it_creates_the_phase_8_clients_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'clients.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'clients.manage')->exists());
    }

    public function test_manager_and_staff_are_granted_clients_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();

        $this->assertTrue($manager->permissions->contains('name', 'clients.view'));
        $this->assertTrue($staff->permissions->contains('name', 'clients.view'));
        $this->assertFalse($manager->permissions->contains('name', 'clients.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'clients.manage'));
    }

    public function test_it_creates_the_phase_9_staff_operations_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'staff-status.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'staff-status.manage')->exists());
        $this->assertTrue(Permission::query()->where('name', 'location.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'location.manage')->exists());
    }

    public function test_manager_and_staff_are_granted_staff_status_view_but_only_manager_gets_location_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();

        $this->assertTrue($manager->permissions->contains('name', 'staff-status.view'));
        $this->assertTrue($staff->permissions->contains('name', 'staff-status.view'));
        $this->assertFalse($manager->permissions->contains('name', 'staff-status.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'staff-status.manage'));

        // Location is materially more sensitive than operational status:
        // Manager gets 'location.view' (further scoped to direct reports
        // at the controller level), Staff never does.
        $this->assertTrue($manager->permissions->contains('name', 'location.view'));
        $this->assertFalse($staff->permissions->contains('name', 'location.view'));
        $this->assertFalse($manager->permissions->contains('name', 'location.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'location.manage'));
    }

    public function test_it_creates_the_phase_10_projects_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'projects.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'projects.manage')->exists());
    }

    public function test_only_manager_is_granted_projects_view_not_staff(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();

        // Unlike every other *.view permission so far, Projects are
        // membership-scoped business information, not company-wide
        // directory data — Staff never gets 'projects.view' (see
        // docs/phases/V1_PHASE_10_DEFINITION.md).
        $this->assertTrue($manager->permissions->contains('name', 'projects.view'));
        $this->assertFalse($staff->permissions->contains('name', 'projects.view'));
        $this->assertFalse($manager->permissions->contains('name', 'projects.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'projects.manage'));
    }

    public function test_it_creates_the_phase_11_tasks_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'tasks.view')->exists());
        $this->assertTrue(Permission::query()->where('name', 'tasks.manage')->exists());
    }

    public function test_only_manager_is_granted_tasks_view_not_staff(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();

        // Mirrors 'projects.view' exactly (docs/phases/
        // V1_PHASE_11_DEFINITION.md) — Tasks may carry the same
        // client-engagement sensitivity as their Project.
        $this->assertTrue($manager->permissions->contains('name', 'tasks.view'));
        $this->assertFalse($staff->permissions->contains('name', 'tasks.view'));
        $this->assertFalse($manager->permissions->contains('name', 'tasks.manage'));
        $this->assertFalse($staff->permissions->contains('name', 'tasks.manage'));
    }

    public function test_running_it_twice_does_not_duplicate_rows(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();

        $this->assertSame(3, Role::query()->count());
        $this->assertSame(16, Permission::query()->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'organization.view')->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'staff.view')->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'clients.view')->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'staff-status.view')->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'location.view')->count());
        $this->assertSame(1, $manager->permissions()->where('name', 'projects.view')->count());
    }
}
