<?php

namespace Tests\Feature\Api\V1\Authorization;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\PermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roles & permissions and Users and roles API (Phase 35C, DEC-065).
 */
class RolesAndUsersApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->administrator()->create(['name' => 'Ada Admin', 'email' => 'ada@company.test']);
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->firstOrFail();
    }

    // ---- The catalog ------------------------------------------------------

    public function test_the_catalog_covers_every_permission_exactly_once_and_its_defaults_match_the_seeder(): void
    {
        $catalog = [...PermissionCatalog::grantable(), ...PermissionCatalog::LOCKED];
        $this->assertSame(count($catalog), count(array_unique($catalog)));
        $this->assertEqualsCanonicalizing(Permission::query()->pluck('name')->all(), $catalog);

        foreach (PermissionCatalog::DEFAULTS as $role => $names) {
            $this->assertEqualsCanonicalizing($this->role($role)->permissions()->pluck('name')->all(), $names, $role);
            foreach ($names as $name) {
                $view = PermissionCatalog::requiredView($name);
                $this->assertTrue($view === null || in_array($view, $names, true), "{$role}: {$name} without {$view}");
            }
        }
    }

    public function test_normalize_adds_the_view_for_a_new_write_and_clears_writes_with_their_view(): void
    {
        $this->assertSame(['tasks.view', 'tasks.create'], PermissionCatalog::normalize([], ['tasks.create']));
        $this->assertSame(['staff.view'], PermissionCatalog::normalize(['tasks.view', 'tasks.create', 'tasks.delete', 'staff.view'], ['tasks.create', 'tasks.delete', 'staff.view']));
        $this->assertSame([], PermissionCatalog::normalize([], ['admin.access', 'nonsense']));
    }

    // ---- Who may use it ---------------------------------------------------

    public function test_only_administrators_may_use_it(): void
    {
        foreach ([User::factory()->manager()->create(), User::factory()->staff()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/roles')->assertForbidden();
            $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => []])->assertForbidden();
            $this->getJson('/api/v1/users')->assertForbidden();
            $this->putJson("/api/v1/users/{$user->public_id}/role", ['role' => 'administrator'])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/roles')->assertUnauthorized();
    }

    public function test_authorization_manage_cannot_be_granted_so_a_manager_cannot_escalate(): void
    {
        Sanctum::actingAs($this->admin);
        foreach (PermissionCatalog::LOCKED as $locked) {
            $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => [$locked]])
                ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        }
        $this->assertFalse($this->role(Role::MANAGER)->permissions()->whereIn('name', PermissionCatalog::LOCKED)->exists());
    }

    // ---- Roles ------------------------------------------------------------

    public function test_it_lists_the_roles_with_their_permissions_and_the_catalog(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/roles')->assertOk()
            ->assertJsonPath('data.0.name', 'administrator')->assertJsonPath('data.0.editable', false)
            ->assertJsonPath('data.1.name', 'manager')->assertJsonPath('data.1.editable', true)
            ->assertJsonPath('data.2.name', 'staff')
            ->assertJsonPath('data.2.permissions', ['organization.view', 'staff.view', 'staff-status.view', 'clients.view', 'leave-types.view'])
            ->assertJsonPath('meta.defaults.staff', PermissionCatalog::DEFAULTS['staff'])
            ->assertJsonPath('meta.locked.0.name', 'admin.access');

        $tasks = collect($response->json('meta.modules'))->firstWhere('title', 'Tasks');
        $this->assertSame(['tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete'], array_column($tasks['permissions'], 'name'));
        $this->assertSame('Add tasks in any project', $tasks['permissions'][1]['label']);
        $this->assertSame('tasks.view', $tasks['permissions'][1]['requires']);
        $this->assertNull($tasks['permissions'][0]['requires']);
        $this->assertStringContainsString('project lead', $tasks['note']);
        $this->assertContains('authorization.manage', $response->json('data.0.permissions'));
    }

    public function test_an_administrator_changes_a_roles_permissions_and_it_takes_effect_and_is_audited(): void
    {
        $managerUser = User::factory()->manager()->create();
        Sanctum::actingAs($this->admin);

        $new = [...PermissionCatalog::DEFAULTS['manager'], 'tasks.create', 'tasks.update'];
        $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => $new])->assertOk()
            ->assertJsonPath('data.name', 'manager');

        $this->assertTrue($managerUser->fresh()->can('tasks.create'));
        $this->assertFalse($managerUser->fresh()->can('tasks.delete'));

        $log = AuditLog::query()->where('action', 'role.permissions_updated')->sole();
        $this->assertSame('role', $log->entity_type);
        $this->assertSame('manager', $log->entity_public_id);
        $this->assertSame($this->admin->id, $log->actor_user_id);
        $this->assertSame(AuditSource::Api, $log->source);
        $this->assertEqualsCanonicalizing(['tasks.create', 'tasks.update'], $log->after['added']);
        $this->assertSame([], $log->before['removed']);

        // The same set again changes nothing and writes no entry.
        $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => $new])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'role.permissions_updated')->count());

        // Removing everything is allowed.
        $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => []])->assertOk()->assertJsonPath('data.permissions', []);
        $this->assertFalse($managerUser->fresh()->can('tasks.view'));
    }

    public function test_a_write_needs_its_view(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/roles/staff/permissions', ['permissions' => ['tasks.delete']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions' => '“Delete tasks” also needs “View all tasks”.']);
        $this->assertFalse($this->role(Role::STAFF)->permissions()->where('name', 'tasks.delete')->exists());
    }

    public function test_administrator_unknown_names_and_bad_bodies_are_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/roles/administrator/permissions', ['permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => ['tasks.manage']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->putJson('/api/v1/roles/manager/permissions', [])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->putJson('/api/v1/roles/manager/permissions', ['permissions' => ['tasks.view', 'tasks.view']])
            ->assertUnprocessable();
        $this->putJson('/api/v1/roles/nobody/permissions', ['permissions' => []])->assertNotFound();
    }

    // ---- Users and roles --------------------------------------------------

    public function test_it_lists_accounts_with_their_roles_and_searches(): void
    {
        User::factory()->staff()->create(['name' => 'Lia Ramos', 'email' => 'lia@company.test']);
        User::factory()->manager()->create(['name' => 'Ben Cruz', 'email' => 'ben@company.test']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/users')->assertOk()
            ->assertJsonPath('data.0.name', 'Ada Admin')->assertJsonPath('data.0.is_me', true)
            ->assertJsonPath('data.1.name', 'Ben Cruz')->assertJsonPath('data.1.role.name', 'manager')
            ->assertJsonPath('data.2.name', 'Lia Ramos')->assertJsonPath('data.2.is_me', false)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.roles.0.name', 'administrator')->assertJsonPath('meta.roles.2.name', 'staff');

        $this->getJson('/api/v1/users?q=LIA@')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Lia Ramos');
        $this->getJson('/api/v1/users?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_administrator_changes_an_accounts_role_and_it_is_audited(): void
    {
        $lia = User::factory()->staff()->create();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$lia->public_id}/role", ['role' => 'manager'])->assertOk()
            ->assertJsonPath('data.role.name', 'manager');
        $this->assertTrue($lia->fresh()->can('tasks.view'));

        $log = AuditLog::query()->where('action', 'user.role_changed')->sole();
        $this->assertSame($lia->public_id, $log->entity_public_id);
        $this->assertSame(['role' => 'staff'], $log->before);
        $this->assertSame(['role' => 'manager'], $log->after);

        $this->putJson("/api/v1/users/{$lia->public_id}/role", ['role' => 'manager'])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'user.role_changed')->count());
        $this->putJson("/api/v1/users/{$lia->public_id}/role", ['role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_you_cannot_change_your_own_role_or_remove_the_last_administrator(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$this->admin->public_id}/role", ['role' => 'staff'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role' => 'You can\'t change your own role.']);

        // Another administrator can be demoted while one remains.
        $other = User::factory()->administrator()->create();
        $this->putJson("/api/v1/users/{$other->public_id}/role", ['role' => 'staff'])->assertOk();

        // The last-Administrator guard is a safety net: through this API the actor is
        // always an Administrator, so one other always remains. Reach it with an
        // account holding authorization.manage directly (never possible in the catalog).
        $keeper = Role::query()->create(['name' => 'keeper', 'label' => 'Keeper']);
        $keeper->permissions()->attach(Permission::query()->where('name', 'authorization.manage')->value('id'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role_id' => $keeper->id]));
        $this->putJson("/api/v1/users/{$this->admin->public_id}/role", ['role' => 'manager'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role' => 'There must always be at least one Administrator.']);
        $this->assertTrue($this->admin->fresh()->hasRole(Role::ADMINISTRATOR));
    }
}
