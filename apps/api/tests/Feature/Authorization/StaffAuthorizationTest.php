<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 7 Staff endpoints
 * (CLAUDE.md §18): 'staff.view' gates reads, 'staff.manage' gates writes
 * — enforced by route middleware, not merely by the controller. Mirrors
 * OrganizationAuthorizationTest's pattern.
 */
class StaffAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Manager/Staff only hold 'staff.view' because the seeder grants
        // it — exercise the real catalog, not a hand-attached stand-in.
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_staff(): void
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/staff')->assertOk();
        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-9001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertCreated();
    }

    public function test_manager_can_view_but_not_manage_staff(): void
    {
        $user = User::factory()->manager()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/staff')->assertOk();

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-9002',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertForbidden();
    }

    public function test_staff_can_view_but_not_manage_staff(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/staff')->assertOk();

        $staffMember = Staff::factory()->create();
        $this->putJson("/api/v1/staff/{$staffMember->public_id}", ['first_name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$staffMember->public_id}")->assertForbidden();
    }

    public function test_user_with_no_role_cannot_view_or_manage_staff(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/staff')->assertForbidden();
        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-9003',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/staff')->assertUnauthorized();
        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-9004',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertUnauthorized();
    }

    public function test_suspended_account_loses_staff_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/staff')
            ->assertForbidden();
    }
}
