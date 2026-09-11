<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests the Phase 5 authorization mechanism itself (Gate::before,
 * Role/Permission relationships, User::hasRole()/hasPermission()) — not
 * just a single surface's UI visibility (CLAUDE.md §18).
 */
class PermissionMechanismTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_no_role_has_no_permissions(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->assertFalse($user->hasPermission('admin.access'));
        $this->assertFalse($user->hasRole(Role::ADMINISTRATOR));
        $this->assertTrue($user->cannot('admin.access'));
    }

    public function test_permission_checks_default_to_deny_for_an_unknown_ability(): void
    {
        $user = User::factory()->staff()->create();

        // 'totally.made-up' matches no row in the permissions table and no
        // Gate::define — must be denied, not merely "not explicitly true".
        $this->assertFalse($user->hasPermission('totally.made-up'));
        $this->assertTrue($user->cannot('totally.made-up'));
    }

    public function test_role_grants_only_the_permissions_explicitly_attached_to_it(): void
    {
        $role = Role::factory()->create();
        $granted = Permission::factory()->create(['name' => 'widgets.manage']);
        $notGranted = Permission::factory()->create(['name' => 'widgets.delete']);

        $role->permissions()->attach($granted);

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($user->hasPermission('widgets.manage'));
        $this->assertFalse($user->hasPermission('widgets.delete'));
        $this->assertTrue($user->can('widgets.manage'));
        $this->assertTrue($user->cannot('widgets.delete'));

        // The inverse relationship also resolves correctly.
        $this->assertTrue($granted->roles->contains('id', $role->id));
        $this->assertFalse($notGranted->roles->contains('id', $role->id));
    }

    public function test_administrator_holds_every_ability_via_the_centralized_override(): void
    {
        $administrator = User::factory()->administrator()->create();

        // No permission named this exists anywhere — Administrator still
        // holds it, via Gate::before's role-based override, not because
        // it was attached to the role (App\Providers\AppServiceProvider).
        $this->assertTrue($administrator->can('anything.whatsoever'));
        $this->assertTrue($administrator->can('admin.access'));
    }

    public function test_manager_does_not_implicitly_gain_administrator_permissions(): void
    {
        $manager = User::factory()->manager()->create();

        $this->assertFalse($manager->hasRole(Role::ADMINISTRATOR));
        $this->assertTrue($manager->cannot('admin.access'));
        $this->assertTrue($manager->cannot('anything.whatsoever'));
    }

    public function test_staff_does_not_implicitly_gain_administrator_permissions(): void
    {
        $staff = User::factory()->staff()->create();

        $this->assertFalse($staff->hasRole(Role::ADMINISTRATOR));
        $this->assertTrue($staff->cannot('admin.access'));
        $this->assertTrue($staff->cannot('anything.whatsoever'));
    }

    public function test_the_transitional_is_admin_column_no_longer_exists(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
    }

    public function test_role_id_is_not_mass_assignable_on_user(): void
    {
        $role = Role::factory()->create();

        $user = User::create([
            'name' => 'Mass Assignment Attempt',
            'email' => 'mass-assignment@example.test',
            'password' => 'irrelevant',
            'role_id' => $role->id,
        ]);

        $this->assertNull($user->fresh()->role_id);
    }
}
