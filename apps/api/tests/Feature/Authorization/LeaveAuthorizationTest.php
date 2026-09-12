<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 13 Leave Management
 * endpoints (CLAUDE.md §18): `leave-types.view` is company-wide;
 * `leave-requests.view` is Manager-only and scoped to direct reports
 * (mirroring Phase 12's `work-logs.view`); `leave-requests.manage` is
 * Administrator-only; approve/reject authority belongs to Administrator
 * or the requester's direct Manager only. See docs/phases/
 * V1_PHASE_13_DEFINITION.md.
 */
class LeaveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_every_leave_request(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $leaveRequest = LeaveRequest::factory()->create();

        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/leave-requests/{$leaveRequest->public_id}")->assertOk();
        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertOk();
    }

    public function test_a_manager_sees_only_their_direct_reports_leave_requests(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $unrelated = Staff::factory()->create();
        $reportRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        LeaveRequest::factory()->create(['staff_id' => $unrelated->id]);
        Sanctum::actingAs($managerUser);

        $this->getJson('/api/v1/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $reportRequest->public_id]]]);
    }

    public function test_a_manager_cannot_view_an_unrelated_staff_members_leave_request(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $unrelated = Staff::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $unrelated->id]);
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/leave-requests/{$leaveRequest->public_id}")->assertForbidden();
    }

    public function test_a_manager_cannot_create_a_leave_request_on_behalf_of_anyone(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $leaveType = LeaveType::factory()->create();
        Sanctum::actingAs($managerUser);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $report->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(1)->toDateString(),
            'reason' => 'Personal reason.',
        ])->assertForbidden();
    }

    public function test_a_manager_cannot_administrator_cancel_a_leave_request(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $leaveRequest = LeaveRequest::factory()->approved()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/cancel")->assertForbidden();
    }

    public function test_a_staff_member_cannot_access_the_top_level_leave_requests_endpoint(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/leave-requests')->assertForbidden();
    }

    public function test_a_staff_member_cannot_see_a_coworkers_leave_request_despite_shared_project_membership(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $coworker = Staff::factory()->create();
        $coworkerRequest = LeaveRequest::factory()->create(['staff_id' => $coworker->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/me/leave-requests/{$coworkerRequest->public_id}")->assertNotFound();
        $this->getJson('/api/v1/leave-requests')->assertForbidden();
    }

    public function test_a_user_with_no_linked_staff_record_cannot_use_self_service(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/leave-requests')->assertForbidden();
        $this->getJson('/api/v1/me/leave-balances')->assertForbidden();
    }

    public function test_a_no_role_user_with_linked_staff_can_still_use_self_service(): void
    {
        $user = User::factory()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/leave-requests')->assertOk();
        $this->getJson('/api/v1/me/leave-balances')->assertOk();
    }

    public function test_a_no_role_user_cannot_access_leave_types_or_the_top_level_leave_requests_endpoint(): void
    {
        $user = User::factory()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/leave-types')->assertForbidden();
        $this->getJson('/api/v1/leave-requests')->assertForbidden();
    }

    public function test_a_no_role_unlinked_user_cannot_use_self_service(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/leave-requests')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $leaveRequest = LeaveRequest::factory()->create();

        $this->getJson('/api/v1/leave-requests')->assertUnauthorized();
        $this->getJson("/api/v1/leave-requests/{$leaveRequest->public_id}")->assertUnauthorized();
        $this->getJson('/api/v1/me/leave-requests')->assertUnauthorized();
        $this->getJson('/api/v1/leave-types')->assertUnauthorized();
    }

    public function test_suspended_account_loses_leave_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/leave-requests')
            ->assertForbidden();
    }
}
