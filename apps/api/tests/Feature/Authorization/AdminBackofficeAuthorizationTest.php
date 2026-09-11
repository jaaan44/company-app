<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Admin Backoffice's
 * `/home` route (CLAUDE.md §18/§12): authenticated -> active account ->
 * authorized for Admin Backoffice (`can:admin.access`) -> /home.
 */
class AdminBackofficeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_access_the_admin_backoffice(): void
    {
        $user = User::factory()->administrator()->create();

        $this->actingAs($user)->get('/home')->assertOk();
    }

    public function test_active_staff_user_without_admin_access_permission_is_denied(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)->get('/home')->assertForbidden();
    }

    public function test_active_manager_user_without_admin_access_permission_is_denied(): void
    {
        $user = User::factory()->manager()->create();

        $this->actingAs($user)->get('/home')->assertForbidden();
    }

    public function test_active_user_with_no_role_at_all_is_denied(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->actingAs($user)->get('/home')->assertForbidden();
    }

    public function test_suspended_administrator_cannot_access_the_admin_backoffice(): void
    {
        $user = User::factory()->administrator()->create();
        $this->actingAs($user);

        // Direct attribute assignment (not update()) since 'status' is
        // deliberately not mass-assignable.
        $user->status = AccountStatus::Suspended;
        $user->save();

        // SessionGuard caches its resolved user for the lifetime of the
        // guard instance — see the equivalent note in AdminLoginTest.
        $this->app['auth']->forgetGuards();

        $this->get('/home')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_administrator_cannot_access_the_admin_backoffice(): void
    {
        $user = User::factory()->administrator()->create();
        $this->actingAs($user);

        $user->status = AccountStatus::Inactive;
        $user->save();

        $this->app['auth']->forgetGuards();

        $this->get('/home')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_unauthenticated_access_redirects_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }
}
