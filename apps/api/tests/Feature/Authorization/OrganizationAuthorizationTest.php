<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 6 Organization Structure
 * endpoints (CLAUDE.md §18): 'organization.view' gates reads,
 * 'organization.manage' gates writes — enforced by route middleware, not
 * merely by the controller. Mirrors AdminBackofficeAuthorizationTest's
 * pattern.
 */
class OrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Manager/Staff only hold 'organization.view' because the seeder
        // grants it — exercise the real catalog, not a hand-attached stand-in.
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_organization_structure(): void
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/departments')->assertOk();
        $this->postJson('/api/v1/departments', ['name' => 'Engineering'])->assertCreated();
    }

    public function test_manager_can_view_but_not_manage_organization_structure(): void
    {
        $user = User::factory()->manager()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/departments')->assertOk();
        $this->getJson('/api/v1/teams')->assertOk();
        $this->getJson('/api/v1/positions')->assertOk();

        $this->postJson('/api/v1/departments', ['name' => 'Engineering'])->assertForbidden();
    }

    public function test_staff_can_view_but_not_manage_organization_structure(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/departments')->assertOk();

        $department = Department::factory()->create();
        $this->putJson("/api/v1/departments/{$department->public_id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/departments/{$department->public_id}")->assertForbidden();
    }

    public function test_user_with_no_role_cannot_view_or_manage_organization_structure(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/departments')->assertForbidden();
        $this->postJson('/api/v1/departments', ['name' => 'Engineering'])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/departments')->assertUnauthorized();
        $this->postJson('/api/v1/departments', ['name' => 'Engineering'])->assertUnauthorized();
    }

    public function test_suspended_account_loses_organization_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/departments')
            ->assertForbidden();
    }
}
