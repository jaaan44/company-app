<?php

namespace Tests\Feature\Api\V1\Leave;

use App\Enums\LeaveRequestStatus;
use App\Enums\StaffStatus;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Approval/rejection/cancellation transitions, approval history, and
 * historical preservation (Phase 13 — Leave Management). See
 * docs/phases/V1_PHASE_13_DEFINITION.md.
 */
class LeaveRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function futureDate(int $days = 10): string
    {
        return Carbon::now()->addYear()->startOfYear()->addDays($days)->toDateString();
    }

    private function managerWithReport(): array
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);

        return [$managerUser, $managerStaff, $report];
    }

    // --- Approval -------------------------------------------------------

    public function test_a_direct_manager_can_approve_a_pending_request(): void
    {
        [$managerUser, , $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")
            ->assertOk()->assertJson(['data' => ['status' => 'approved']]);

        $this->assertSame(LeaveRequestStatus::Approved, $leaveRequest->fresh()->status);
    }

    public function test_a_direct_manager_can_reject_a_pending_request_with_a_reason(): void
    {
        [$managerUser, , $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/reject", ['reason' => 'Insufficient coverage.'])
            ->assertOk()->assertJson(['data' => ['status' => 'rejected']]);
    }

    public function test_rejecting_without_a_reason_is_rejected(): void
    {
        [$managerUser, , $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/reject", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_an_unrelated_manager_cannot_approve_a_request(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $unrelated = Staff::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $unrelated->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertForbidden();
    }

    public function test_a_manager_cannot_approve_their_own_request(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $managerStaff->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertForbidden();
    }

    public function test_administrator_can_approve_or_reject_any_request(): void
    {
        $this->actingAsAdministrator();
        $leaveRequest = LeaveRequest::factory()->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertOk();
    }

    public function test_a_staff_member_with_no_manager_can_only_be_decided_by_an_administrator(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $orphan = Staff::factory()->create(['manager_id' => null]);
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $orphan->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertForbidden();

        $this->actingAsAdministrator();
        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertOk();
    }

    public function test_approving_an_already_decided_request_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $leaveRequest = LeaveRequest::factory()->approved()->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertStatus(409);
    }

    public function test_rejected_requests_are_immutable(): void
    {
        $this->actingAsAdministrator();
        $leaveRequest = LeaveRequest::factory()->rejected()->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertStatus(409);
        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/reject", ['reason' => 'Reason.'])->assertStatus(409);
        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/cancel")->assertStatus(409);
    }

    // --- History ----------------------------------------------------------

    public function test_submission_creates_a_submitted_history_entry(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $leaveType = LeaveType::factory()->unpaid()->create();

        $response = $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertCreated();

        $response->assertJsonCount(1, 'data.history')
            ->assertJson(['data' => ['history' => [['action' => 'submitted', 'acted_by' => ['public_id' => $staff->public_id]]]]]);
    }

    public function test_approval_and_rejection_are_recorded_in_history(): void
    {
        [$managerUser, , $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve", ['note' => 'Approved.'])->assertOk();

        $this->assertDatabaseHas('leave_request_actions', [
            'leave_request_id' => $leaveRequest->id,
            'action' => 'approved',
            'note' => 'Approved.',
        ]);
    }

    public function test_rejection_is_recorded_in_history(): void
    {
        [$managerUser, , $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/reject", ['reason' => 'No coverage available.'])->assertOk();

        $this->assertDatabaseHas('leave_request_actions', [
            'leave_request_id' => $leaveRequest->id,
            'action' => 'rejected',
            'note' => 'No coverage available.',
        ]);
    }

    public function test_cancellation_is_recorded_in_history(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}/cancel", ['reason' => 'Plans changed.'])
            ->assertOk()->assertJson(['data' => ['status' => 'cancelled']]);

        $this->assertDatabaseHas('leave_request_actions', [
            'leave_request_id' => $leaveRequest->id,
            'action' => 'cancelled',
            'note' => 'Plans changed.',
        ]);
    }

    public function test_the_actual_approver_is_preserved_after_a_later_manager_change(): void
    {
        [$managerUser, $managerStaff, $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertOk();

        // The report gets a new manager after the decision was made.
        $newManagerUser = User::factory()->manager()->create();
        $newManagerStaff = Staff::factory()->create(['user_id' => $newManagerUser->id]);
        $report->update(['manager_id' => $newManagerStaff->id]);

        $this->actingAsAdministrator();
        $history = $this->getJson("/api/v1/leave-requests/{$leaveRequest->public_id}")
            ->assertOk()->json('data.history');

        $approvedEntry = collect($history)->firstWhere('action', 'approved');
        $this->assertSame($managerStaff->public_id, $approvedEntry['acted_by']['public_id']);
    }

    // --- Cancellation -----------------------------------------------------

    public function test_requester_can_cancel_their_own_pending_request(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}/cancel")
            ->assertOk()->assertJson(['data' => ['status' => 'cancelled']]);
    }

    public function test_requester_can_cancel_their_own_approved_request_before_it_starts(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'start_date' => $this->futureDate(5),
            'end_date' => $this->futureDate(6),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}/cancel")->assertOk();
    }

    public function test_requester_cannot_cancel_their_own_approved_request_after_it_has_started(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'start_date' => Carbon::now()->subDays(2)->toDateString(),
            'end_date' => Carbon::now()->subDay()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}/cancel")->assertStatus(409);
    }

    public function test_requester_cannot_cancel_a_rejected_request(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->rejected()->create(['staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}/cancel")->assertStatus(409);
    }

    public function test_requester_cannot_cancel_an_already_cancelled_request(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->cancelled()->create(['staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}/cancel")->assertStatus(409);
    }

    public function test_administrator_can_cancel_an_approved_request_after_it_has_started(): void
    {
        $this->actingAsAdministrator();
        $leaveRequest = LeaveRequest::factory()->approved()->create([
            'start_date' => Carbon::now()->subDays(2)->toDateString(),
            'end_date' => Carbon::now()->subDay()->toDateString(),
        ]);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/cancel")->assertOk()
            ->assertJson(['data' => ['status' => 'cancelled']]);
    }

    public function test_a_manager_cannot_cancel_a_direct_reports_request(): void
    {
        [$managerUser, , $report] = $this->managerWithReport();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/cancel")->assertForbidden();
    }

    public function test_cancelling_an_approved_request_restores_the_leave_balance(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 3]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(3),
            'reason' => 'First.',
        ])->assertCreated();

        $publicId = $response->json('data.public_id');

        $this->actingAsAdministrator();
        $this->postJson("/api/v1/leave-requests/{$publicId}/approve")->assertOk();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/me/leave-requests/{$publicId}/cancel")->assertOk();

        // The full allocation is now available again for a new request.
        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(3),
            'reason' => 'Second, after cancellation.',
        ])->assertCreated();
    }

    // --- Historical preservation -------------------------------------------

    public function test_a_staff_members_leave_requests_remain_after_they_become_inactive(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveRequest = LeaveRequest::factory()->approved()->create(['staff_id' => $staff->id]);

        $staff->update(['status' => StaffStatus::Separated]);

        $this->actingAsAdministrator();
        $this->getJson("/api/v1/leave-requests/{$leaveRequest->public_id}")
            ->assertOk()->assertJson(['data' => ['status' => 'approved']]);
    }
}
