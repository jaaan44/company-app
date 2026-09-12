<?php

namespace Tests\Feature\Api\V1\Leave;

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

class LeaveRequestTest extends TestCase
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

    private function actingAsLinkedStaff(): Staff
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $staff;
    }

    private function futureDate(int $days = 10): string
    {
        return Carbon::now()->addYear()->startOfYear()->addDays($days)->toDateString();
    }

    // --- Self-service creation -----------------------------------------

    public function test_an_active_staff_member_can_submit_a_leave_request(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        $start = $this->futureDate(10);
        $end = $this->futureDate(12);
        LeaveBalance::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'year' => Carbon::parse($start)->year,
            'allocated_days' => 10,
        ]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Family trip.',
        ])->assertCreated()->assertJson([
            'data' => [
                'leave_type' => ['public_id' => $leaveType->public_id],
                'status' => 'pending',
                'total_days' => 3,
            ],
        ])->assertJsonMissingPath('data.id')
            ->assertJsonCount(1, 'data.history')
            ->assertJson(['data' => ['history' => [['action' => 'submitted']]]]);
    }

    public function test_a_user_with_no_linked_staff_record_cannot_submit_a_leave_request(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertForbidden();
    }

    public function test_an_inactive_staff_member_cannot_submit_a_new_leave_request(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->inactive()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_an_unknown_leave_type_public_id_is_rejected(): void
    {
        $this->actingAsLinkedStaff();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => 'not-a-real-id',
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_an_inactive_leave_type_is_rejected_for_a_new_self_service_request(): void
    {
        $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->inactive()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(5),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_a_request_may_not_span_two_calendar_years(): void
    {
        $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        $start = Carbon::now()->addYear()->endOfYear()->toDateString();
        $end = Carbon::now()->addYear()->endOfYear()->addDays(2)->toDateString();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    // --- Overlap ----------------------------------------------------------

    public function test_an_overlapping_pending_request_is_rejected(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        LeaveRequest::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(15),
            'total_days' => 6,
        ]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(12),
            'end_date' => $this->futureDate(20),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_an_overlapping_approved_request_is_rejected_regardless_of_leave_type(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        $otherType = LeaveType::factory()->unpaid()->create();
        LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(15),
            'total_days' => 6,
        ]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $otherType->public_id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(10),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_a_rejected_request_does_not_block_a_new_overlapping_request(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->unpaid()->create();
        LeaveRequest::factory()->rejected()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(15),
            'total_days' => 6,
        ]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(10),
            'reason' => 'Personal reason.',
        ])->assertCreated();
    }

    public function test_a_cancelled_request_does_not_block_a_new_overlapping_request(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->unpaid()->create();
        LeaveRequest::factory()->cancelled()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(15),
            'total_days' => 6,
        ]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(10),
            'reason' => 'Personal reason.',
        ])->assertCreated();
    }

    // --- Balances -----------------------------------------------------

    public function test_a_request_within_the_allocated_balance_is_accepted(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 10]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(5),
            'reason' => 'Personal reason.',
        ])->assertCreated();
    }

    public function test_a_request_exceeding_the_allocated_balance_is_rejected(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 2]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(5),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_no_balance_row_means_zero_allocation_and_is_rejected(): void
    {
        $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_an_unpaid_leave_type_bypasses_the_balance_check_entirely(): void
    {
        $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->unpaid()->create();

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(30),
            'reason' => 'Personal reason.',
        ])->assertCreated();
    }

    public function test_two_pending_requests_together_cannot_exceed_the_allocation(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 5]);

        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(3),
            'reason' => 'First.',
        ])->assertCreated();

        // 3 days already pending; only 2 remain, so a 3-day second request
        // (on non-overlapping dates) must be rejected.
        $this->postJson('/api/v1/me/leave-requests', [
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(20),
            'end_date' => $this->futureDate(22),
            'reason' => 'Second.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    // --- Visibility -----------------------------------------------------

    public function test_a_staff_member_sees_only_their_own_leave_requests(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $ownRequest = LeaveRequest::factory()->create(['staff_id' => $staff->id]);
        LeaveRequest::factory()->create();

        $this->getJson('/api/v1/me/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $ownRequest->public_id]]]);
    }

    public function test_viewing_another_staff_members_leave_request_via_me_returns_404(): void
    {
        $this->actingAsLinkedStaff();
        $otherRequest = LeaveRequest::factory()->create();

        $this->getJson("/api/v1/me/leave-requests/{$otherRequest->public_id}")->assertNotFound();
    }

    public function test_no_edit_endpoint_exists_for_leave_requests(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $leaveRequest = LeaveRequest::factory()->create(['staff_id' => $staff->id]);

        $this->putJson("/api/v1/me/leave-requests/{$leaveRequest->public_id}", ['reason' => 'Changed'])
            ->assertStatus(405);
    }

    // --- Administrator creation on behalf of staff ------------------------

    public function test_administrator_can_create_a_leave_request_on_behalf_of_a_staff_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'year' => Carbon::parse($this->futureDate(1))->year,
            'allocated_days' => 10,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(2),
            'reason' => 'Recorded historically.',
        ])->assertCreated()->assertJson(['data' => ['status' => 'pending', 'staff' => ['public_id' => $staff->public_id]]]);
    }

    /**
     * Administrator bypasses Staff active-employment-status eligibility
     * — but never balance sufficiency, so an allocation must still exist
     * (docs/phases/V1_PHASE_13_DEFINITION.md's Leave Type Validity vs.
     * Self-Service Staff Eligibility).
     */
    public function test_administrator_creation_skips_the_active_staff_eligibility_check(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->inactive()->create();
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'year' => Carbon::parse($this->futureDate(1))->year,
            'allocated_days' => 5,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertCreated();
    }

    /**
     * Administrator NEVER bypasses balance sufficiency — there is no
     * override/negative-balance concept in this module (DEC-036). A
     * Staff member with no allocation row is treated as allocated_days=0,
     * so any positive-day paid-leave request is rejected until an
     * Administrator sets one via the Leave Balance management endpoint.
     */
    public function test_administrator_creation_is_rejected_when_no_balance_allocation_exists(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(10),
            'reason' => 'No balance configured yet.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_administrator_creation_is_rejected_when_exceeding_remaining_allocation(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'year' => Carbon::parse($this->futureDate(1))->year,
            'allocated_days' => 2,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(5),
            'reason' => 'Exceeds allocation.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_administrator_creation_succeeds_with_sufficient_allocation(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'year' => Carbon::parse($this->futureDate(1))->year,
            'allocated_days' => 10,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(5),
            'reason' => 'Within allocation.',
        ])->assertCreated();
    }

    public function test_administrator_created_pending_requests_consume_capacity_like_self_service(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'year' => Carbon::parse($this->futureDate(1))->year,
            'allocated_days' => 5,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(3),
            'reason' => 'First, by Administrator.',
        ])->assertCreated();

        // 3 days already pending; only 2 remain, so a further 3-day
        // request (on non-overlapping dates) must be rejected — exactly
        // the invariant self-service submissions maintain.
        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(20),
            'end_date' => $this->futureDate(22),
            'reason' => 'Second, by Administrator.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_administrator_can_create_an_unpaid_leave_request_without_a_balance_allocation(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->unpaid()->create();

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(30),
            'reason' => 'Unpaid, no allocation needed.',
        ])->assertCreated();
    }

    public function test_administrator_creation_still_enforces_leave_type_activity(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->inactive()->create();

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    public function test_administrator_creation_still_enforces_overlap(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();
        LeaveRequest::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $this->futureDate(10),
            'end_date' => $this->futureDate(15),
            'total_days' => 6,
        ]);

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $staff->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(12),
            'end_date' => $this->futureDate(13),
            'reason' => 'Personal reason.',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_staff_cannot_create_a_leave_request_on_behalf_of_another_staff_member(): void
    {
        $this->actingAsLinkedStaff();
        $target = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();

        $this->postJson('/api/v1/leave-requests', [
            'staff_id' => $target->public_id,
            'leave_type_id' => $leaveType->public_id,
            'start_date' => $this->futureDate(1),
            'end_date' => $this->futureDate(1),
            'reason' => 'Personal reason.',
        ])->assertForbidden();
    }

    // --- Filters ------------------------------------------------------

    public function test_own_leave_requests_can_be_filtered_by_status(): void
    {
        $staff = $this->actingAsLinkedStaff();
        LeaveRequest::factory()->create(['staff_id' => $staff->id]);
        LeaveRequest::factory()->approved()->create(['staff_id' => $staff->id]);

        $this->getJson('/api/v1/me/leave-requests?status=approved')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['status' => 'approved']]]);
    }

    public function test_own_leave_requests_can_be_filtered_by_type(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $type = LeaveType::factory()->create();
        LeaveRequest::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $type->id]);
        LeaveRequest::factory()->create(['staff_id' => $staff->id]);

        $this->getJson("/api/v1/me/leave-requests?type={$type->public_id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
