<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 9 Staff Operational
 * Status & Location Check-in endpoints (CLAUDE.md §18): `staff-status.view`/
 * `staff-status.manage` gate another staff member's status, `location.view`/
 * `location.manage` gate another staff member's check-ins, self-service
 * ("me") routes require a linked Staff record instead of a permission.
 * Mirrors StaffAuthorizationTest/ClientsAuthorizationTest's pattern, plus
 * this phase's own precise-location visibility rules (DEC-032).
 */
class StaffOperationsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_do_everything(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $other = Staff::factory()->create();
        $checkIn = StaffCheckIn::factory()->for($other, 'staff')->create();

        $this->getJson("/api/v1/staff/{$other->public_id}/status")->assertOk();
        $this->postJson("/api/v1/staff/{$other->public_id}/status", ['status' => 'busy'])->assertCreated();
        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertOk();
        $this->deleteJson("/api/v1/check-ins/{$checkIn->public_id}")->assertNoContent();
    }

    public function test_manager_can_view_status_and_scoped_location_but_not_manage_status(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $nonReport = Staff::factory()->create();
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$report->public_id}/status")->assertOk();
        $this->postJson("/api/v1/staff/{$report->public_id}/status", ['status' => 'busy'])->assertForbidden();

        $this->getJson("/api/v1/staff/{$report->public_id}/check-ins")->assertOk();
        $this->getJson("/api/v1/staff/{$nonReport->public_id}/check-ins")->assertForbidden();
    }

    public function test_staff_can_self_service_but_not_view_others_location_or_manage_status(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $other = Staff::factory()->create();
        Sanctum::actingAs($user);

        // Self-service.
        $this->getJson('/api/v1/me/status')->assertOk();
        $this->postJson('/api/v1/me/status', ['status' => 'available'])->assertCreated();
        $this->getJson('/api/v1/me/check-ins')->assertOk();
        $this->postJson('/api/v1/me/check-ins', ['latitude' => 0, 'longitude' => 0])->assertCreated();

        // Company-wide, low-sensitivity status viewing is allowed.
        $this->getJson("/api/v1/staff/{$other->public_id}/status")->assertOk();

        // Staff may never manage another staff member's status, nor view
        // another staff member's precise location.
        $this->postJson("/api/v1/staff/{$other->public_id}/status", ['status' => 'busy'])->assertForbidden();
        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertForbidden();
    }

    public function test_user_with_no_role_cannot_view_another_staff_members_status_or_location(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        $other = Staff::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/staff/{$other->public_id}/status")->assertForbidden();
        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertForbidden();
    }

    public function test_a_user_with_no_linked_staff_record_is_rejected_from_all_self_service_routes(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/status')->assertForbidden();
        $this->postJson('/api/v1/me/status', ['status' => 'available'])->assertForbidden();
        $this->getJson('/api/v1/me/check-ins')->assertForbidden();
        $this->postJson('/api/v1/me/check-ins', ['latitude' => 0, 'longitude' => 0])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $other = Staff::factory()->create();

        $this->getJson('/api/v1/me/status')->assertUnauthorized();
        $this->postJson('/api/v1/me/status', ['status' => 'available'])->assertUnauthorized();
        $this->getJson('/api/v1/me/check-ins')->assertUnauthorized();
        $this->postJson('/api/v1/me/check-ins', ['latitude' => 0, 'longitude' => 0])->assertUnauthorized();
        $this->getJson("/api/v1/staff/{$other->public_id}/status")->assertUnauthorized();
        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertUnauthorized();
    }

    public function test_suspended_account_loses_self_service_access_mid_session(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/status')
            ->assertForbidden();
    }

    // --- Precise-location visibility rules -------------------------------

    public function test_ordinary_staff_never_obtains_another_employees_precise_location(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $other = Staff::factory()->create();
        StaffCheckIn::factory()->for($other, 'staff')->create(['latitude' => 12.34, 'longitude' => 56.78]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertForbidden();
    }

    public function test_manager_visibility_is_limited_to_direct_reports_not_the_whole_company(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $otherDepartmentStaff = Staff::factory()->create();
        StaffCheckIn::factory()->for($report, 'staff')->create();
        StaffCheckIn::factory()->for($otherDepartmentStaff, 'staff')->create();
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$report->public_id}/check-ins")->assertOk();
        $this->getJson("/api/v1/staff/{$otherDepartmentStaff->public_id}/check-ins")->assertForbidden();
    }
}
