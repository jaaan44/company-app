<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditSource;
use App\Livewire\Admin\AuditLog as AuditLogPage;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Users;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Authorization\PermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin Backoffice (Phase 35B: shell, Dashboard, section pages; Phase 35C:
 * Roles & permissions, Users and roles, Audit log).
 */
class AdminBackofficePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-13 02:00:00', 'UTC'));
        $this->admin = User::factory()->administrator()->create(['name' => 'Ada Admin', 'email' => 'ada@company.test']);
    }

    private const PAGES = ['/admin', '/admin/people', '/admin/administration', '/admin/administration/roles', '/admin/administration/users', '/admin/administration/audit-log'];

    // ---- Access -----------------------------------------------------------

    public function test_only_administrators_may_open_the_backoffice(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertRedirect('/login');
        }
        foreach ([User::factory()->manager()->create(), User::factory()->staff()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            foreach (self::PAGES as $page) {
                $this->actingAs($user, 'web')->get($page)->assertForbidden();
            }
        }
        $this->app['auth']->forgetGuards();
        foreach (self::PAGES as $page) {
            $this->actingAs($this->admin, 'web')->get($page)->assertOk();
        }
        $this->get('/admin/nowhere')->assertNotFound();
    }

    public function test_home_redirects_and_the_portal_links_here_and_back(): void
    {
        $this->get('/home')->assertRedirect('/admin');
        $this->actingAs($this->admin, 'web')->get('/app')->assertSee('href="'.route('admin.dashboard').'" data-no-navigate', false);
        $this->get('/admin')->assertSee('href="'.route('portal.home').'" data-no-navigate', false)->assertSee('Staff portal');
    }

    // ---- Shell ------------------------------------------------------------

    public function test_the_navigation_lists_the_sections_in_order_and_marks_the_current_one(): void
    {
        $page = $this->actingAs($this->admin, 'web')->get('/admin/work')->assertOk()
            ->assertSee('<title>Work — Company App Admin</title>', false)
            ->assertSeeInOrder(['Dashboard', 'People', 'Clients', 'Work', 'HR', 'Operations', 'Communication', 'Reports', 'Administration'])
            ->getContent();
        $this->assertSame(2, preg_match_all('#href="[^"]*/admin/work"\s+aria-current="page"#', $page)); // sidebar and drawer
        $this->assertSame(2, preg_match_all('#aria-current="page"#', $page));

        $roles = $this->get('/admin/administration/roles')->getContent();
        $this->assertSame(2, preg_match_all('#href="[^"]*/admin/administration"\s+aria-current="page"#', $roles));
        $this->assertStringContainsString('aria-label="Back to Administration"', $roles);
    }

    public function test_sections_show_only_to_those_holding_one_of_their_permissions(): void
    {
        $role = Role::query()->create(['name' => 'clerk', 'label' => 'Clerk']);
        $role->permissions()->sync(Permission::query()->whereIn('name', ['admin.access', 'clients.view'])->pluck('id'));
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'web')->get('/admin/clients')->assertOk()
            ->assertSee('href="'.route('admin.section', 'clients').'"', false)
            ->assertSee('href="'.route('admin.section', 'reports').'"', false)
            ->assertDontSee('href="'.route('admin.section', 'people').'"', false)
            ->assertDontSee('href="'.route('admin.section', 'administration').'"', false);
    }

    public function test_a_section_page_lists_what_is_coming_and_administration_links_its_screens(): void
    {
        $this->actingAs($this->admin, 'web')->get('/admin/work')
            ->assertSeeInOrder(['Projects', 'Coming in Phase 36', 'Tasks', 'Work logs', 'Schedule']);
        $this->get('/admin/administration')
            ->assertSee('href="'.route('admin.roles').'"', false)
            ->assertSee('href="'.route('admin.users').'"', false)
            ->assertSee('href="'.route('admin.audit-log').'"', false)
            ->assertDontSee('Coming in');
    }

    // ---- Dashboard --------------------------------------------------------

    public function test_the_dashboard_shows_the_apis_figures(): void
    {
        Staff::factory()->count(3)->create();
        Client::factory()->count(2)->create();
        Task::factory()->create(['due_date' => '2026-10-01']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(Dashboard::class)
            ->assertSeeInOrder(['People', '3', 'active staff', 'Clients', '2', 'active clients'])
            ->assertSeeInOrder(['Tasks', '1', 'overdue', 'To do', '1'])
            ->assertSee('This month:')->assertSee('1 Oct 2026')->assertSee('31 Oct 2026')
            ->set('from', '2026-09-01')->set('to', '2026-09-30')->call('$refresh')
            ->assertSee('Period:')->assertSee('1 Sep 2026')
            ->call('thisMonth')->assertSet('from', '')->assertSee('This month:');
    }

    public function test_an_invalid_period_shows_the_apis_message(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                throw new PortalApiException(422, 'Invalid.', ['to' => ['The to field must be a date after or equal to from.']]);
            }
        });
        $this->actingAs($this->admin, 'web');

        Livewire::test(Dashboard::class)->set('from', '2026-10-10')->set('to', '2026-10-01')
            ->assertSee('The to field must be a date after or equal to from.')
            ->assertSee('aria-invalid="true"', false);
    }

    // ---- Roles & permissions ----------------------------------------------

    public function test_the_roles_screen_shows_modules_notes_and_locked_permissions(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(Roles::class)
            ->assertSet('grants.staff', ['organization.view', 'staff.view', 'staff-status.view', 'clients.view', 'leave-types.view'])
            ->assertSeeInOrder(['Always Administrators only', 'Access the Admin Backoffice', "Manage users' roles and permissions"])
            ->assertSeeInOrder(['Tasks', 'A project lead can always add and edit', 'View all tasks', 'Add tasks in any project'])
            ->assertDontSee('value="admin.access"', false)
            ->assertDontSee('value="authorization.manage"', false)
            ->assertSee('value="tasks.delete"', false);
    }

    public function test_ticking_a_write_ticks_its_view_and_unticking_a_view_clears_its_writes(): void
    {
        $this->actingAs($this->admin, 'web');

        $component = Livewire::test(Roles::class)
            ->set('grants.staff', [...PermissionCatalog::DEFAULTS['staff'], 'tasks.create']);
        $this->assertContains('tasks.view', $component->get('grants.staff'));
        $this->assertContains('tasks.create', $component->get('grants.staff'));

        $component->set('grants.staff', array_values(array_diff($component->get('grants.staff'), ['tasks.view'])));
        $this->assertNotContains('tasks.create', $component->get('grants.staff'));
        $this->assertNotContains('tasks.view', $component->get('grants.staff'));
    }

    public function test_save_lists_the_changes_then_saves_audits_and_takes_effect(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($this->admin, 'web');

        Livewire::test(Roles::class)
            ->set('grants.manager', [...PermissionCatalog::DEFAULTS['manager'], 'tasks.create'])
            ->assertSee('Save these changes?')->assertSeeInOrder(['Manager', 'Can now:', 'Add tasks in any project'])
            ->assertSee('1 role changed, not saved yet')
            ->call('save')
            ->assertSet('savedMessage', 'Permissions saved.')
            ->assertDontSee('not saved yet');

        $this->assertTrue($manager->fresh()->can('tasks.create'));
        $log = AuditLog::query()->where('action', 'role.permissions_updated')->sole();
        $this->assertSame(AuditSource::Web, $log->source);
        $this->assertSame(['tasks.create'], $log->after['added']);
    }

    public function test_undo_and_reset_to_defaults(): void
    {
        $this->role(Role::STAFF)->permissions()->attach(Permission::query()->where('name', 'tasks.view')->value('id'));
        $this->actingAs($this->admin, 'web');

        Livewire::test(Roles::class)
            ->assertSet('grants.staff', ['organization.view', 'staff.view', 'staff-status.view', 'clients.view', 'tasks.view', 'leave-types.view'])
            ->call('resetToDefaults')
            ->assertSee('No longer:')->assertSee('View all tasks')
            ->call('discard')
            ->assertDontSee('not saved yet')
            ->call('resetToDefaults')->call('save');

        $this->assertFalse($this->role(Role::STAFF)->permissions()->where('name', 'tasks.view')->exists());
    }

    public function test_a_refused_save_shows_the_apis_message(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'PUT') {
                    throw new PortalApiException(422, 'Invalid.', ['permissions' => ['“Delete tasks” also needs “View all tasks”.']]);
                }

                return ['data' => [
                    ['name' => 'manager', 'label' => 'Manager', 'editable' => true, 'permissions' => []],
                ], 'meta' => ['modules' => [], 'locked' => [], 'administrator_only' => '', 'defaults' => []]];
            }
        });
        $this->actingAs($this->admin, 'web');

        Livewire::test(Roles::class)->set('grants.manager', ['tasks.view'])->call('save')
            ->assertSee('“Delete tasks” also needs “View all tasks”.')
            ->assertSet('savedMessage', null);
    }

    // ---- Users and roles --------------------------------------------------

    public function test_users_lists_accounts_searches_and_changes_a_role(): void
    {
        $lia = User::factory()->staff()->create(['name' => 'Lia Ramos', 'email' => 'lia@company.test']);
        User::factory()->manager()->create(['name' => 'Ben Cruz', 'email' => 'ben@company.test']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(Users::class)
            ->assertSeeInOrder(['Ada Admin', '(you)', 'You can\'t change your own role.', 'Ben Cruz', 'Lia Ramos', 'lia@company.test'])
            ->assertSee('Role for Lia Ramos')->assertDontSee('Role for Ada Admin')
            ->set('search', 'lia')->assertSee('Lia Ramos')->assertDontSee('Ben Cruz')
            ->call('changeRole', $lia->public_id, 'manager')
            ->assertSet('savedMessage', 'Lia Ramos is now Manager.');

        $this->assertTrue($lia->fresh()->hasRole(Role::MANAGER));
        $this->assertSame(AuditSource::Web, AuditLog::query()->where('action', 'user.role_changed')->sole()->source);
    }

    public function test_changing_your_own_role_shows_the_apis_message(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'PUT') {
                    throw new PortalApiException(422, 'Invalid.', ['role' => ['You can\'t change your own role.']]);
                }

                return ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'roles' => []]];
            }
        });

        Livewire::test(Users::class)->call('changeRole', $this->admin->public_id, 'staff')
            ->assertSee('You can\'t change your own role.')->assertSet('savedMessage', null);
    }

    // ---- Audit log --------------------------------------------------------

    public function test_the_audit_log_lists_entries_newest_first_and_filters_by_action(): void
    {
        $lia = User::factory()->staff()->create(['name' => 'Lia Ramos']);
        $this->actingAs($this->admin, 'web');
        Livewire::test(Users::class)->call('changeRole', $lia->public_id, 'manager');
        Livewire::test(Roles::class)->set('grants.staff', [...PermissionCatalog::DEFAULTS['staff'], 'tasks.view'])->call('save');

        Livewire::test(AuditLogPage::class)
            ->assertSeeInOrder(['role.permissions_updated', 'Ada Admin', 'role', 'staff', 'from web', 'user.role_changed', $lia->public_id])
            ->assertSee('"manager"')
            ->set('action', 'user.role_changed')
            ->assertSee('user.role_changed')->assertDontSee('role.permissions_updated</span>', false);
    }

    public function test_roles_and_users_need_authorization_manage_besides_admin_access(): void
    {
        $role = Role::query()->create(['name' => 'clerk', 'label' => 'Clerk']);
        $role->permissions()->sync(Permission::query()->whereIn('name', ['admin.access'])->pluck('id'));
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'web');

        $this->get('/admin/administration/roles')->assertForbidden();
        $this->get('/admin/administration/users')->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('href="'.route('admin.section', 'administration').'"', false);
    }

    public function test_the_audit_log_follows_the_apis_administrator_only_rule(): void
    {
        $role = Role::query()->create(['name' => 'clerk', 'label' => 'Clerk']);
        $role->permissions()->sync(Permission::query()->whereIn('name', ['admin.access'])->pluck('id'));
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'web')->get('/admin/administration/audit-log')
            ->assertOk()->assertSee('Only Administrators can see the audit log.');
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->firstOrFail();
    }
}
