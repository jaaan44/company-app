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

/**
 * Leave Balances (Phase 13) — allocation, derived usage, and visibility.
 * See docs/phases/V1_PHASE_13_DEFINITION.md.
 */
class LeaveBalanceTest extends TestCase
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

    public function test_administrator_can_set_a_staff_members_leave_balance_allocation(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();

        $this->postJson("/api/v1/staff/{$staff->public_id}/leave-balances", [
            'leave_type_id' => $leaveType->public_id,
            'year' => 2027,
            'allocated_days' => 15,
        ])->assertOk()->assertJson([
            'data' => ['leave_type' => ['public_id' => $leaveType->public_id], 'year' => 2027, 'allocated_days' => 15, 'remaining_days' => 15],
        ]);

        $this->assertDatabaseHas('leave_balances', ['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => 2027, 'allocated_days' => 15]);
    }

    public function test_setting_an_allocation_twice_for_the_same_year_updates_it_rather_than_duplicating(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $leaveType = LeaveType::factory()->create();

        $this->postJson("/api/v1/staff/{$staff->public_id}/leave-balances", [
            'leave_type_id' => $leaveType->public_id, 'year' => 2027, 'allocated_days' => 10,
        ])->assertOk();

        $this->postJson("/api/v1/staff/{$staff->public_id}/leave-balances", [
            'leave_type_id' => $leaveType->public_id, 'year' => 2027, 'allocated_days' => 20,
        ])->assertOk()->assertJson(['data' => ['allocated_days' => 20]]);

        $this->assertSame(1, LeaveBalance::query()->where('staff_id', $staff->id)->where('leave_type_id', $leaveType->id)->where('year', 2027)->count());
    }

    public function test_a_staff_member_can_view_their_own_leave_balances(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => (int) date('Y'), 'allocated_days' => 12]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/leave-balances')
            ->assertOk()
            ->assertJson(['data' => [['leave_type' => ['public_id' => $leaveType->public_id], 'allocated_days' => 12, 'used_days' => 0, 'remaining_days' => 12]]]);
    }

    public function test_leave_balances_report_every_active_leave_type_even_without_an_allocation_row(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        LeaveType::factory()->create();
        LeaveType::factory()->inactive()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/leave-balances')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['allocated_days' => 0, 'used_days' => 0, 'remaining_days' => 0]]]);
    }

    public function test_an_unpaid_leave_type_reports_null_allocation_and_remaining(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        LeaveType::factory()->unpaid()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/leave-balances')
            ->assertOk()
            ->assertJson(['data' => [['is_paid' => false, 'allocated_days' => null, 'remaining_days' => null]]]);
    }

    public function test_a_manager_can_view_a_direct_reports_leave_balances(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$report->public_id}/leave-balances")->assertOk();
    }

    public function test_a_manager_cannot_view_an_unrelated_staff_members_leave_balances(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $unrelated = Staff::factory()->create();
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$unrelated->public_id}/leave-balances")->assertForbidden();
    }

    public function test_an_approved_leave_request_is_reflected_as_used_days(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 10]);
        LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::now()->addYear()->startOfYear()->addDays(1)->toDateString(),
            'end_date' => Carbon::now()->addYear()->startOfYear()->addDays(3)->toDateString(),
            'total_days' => 3,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/me/leave-balances?year={$year}")
            ->assertOk()
            ->assertJson(['data' => [['used_days' => 3, 'pending_days' => 0, 'remaining_days' => 7]]]);
    }

    public function test_a_pending_leave_request_reduces_remaining_but_not_used_days(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 10]);
        LeaveRequest::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::now()->addYear()->startOfYear()->addDays(1)->toDateString(),
            'end_date' => Carbon::now()->addYear()->startOfYear()->addDays(3)->toDateString(),
            'total_days' => 3,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/me/leave-balances?year={$year}")
            ->assertOk()
            ->assertJson(['data' => [['used_days' => 0, 'pending_days' => 3, 'remaining_days' => 7]]]);
    }

    public function test_balances_are_scoped_to_the_correct_calendar_year(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveType = LeaveType::factory()->create();
        $thisYear = Carbon::now()->addYear()->year;
        $nextYear = $thisYear + 1;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $thisYear, 'allocated_days' => 10]);
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $nextYear, 'allocated_days' => 20]);
        LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::create($nextYear, 3, 1)->toDateString(),
            'end_date' => Carbon::create($nextYear, 3, 2)->toDateString(),
            'total_days' => 2,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/me/leave-balances?year={$thisYear}")
            ->assertOk()->assertJson(['data' => [['allocated_days' => 10, 'used_days' => 0, 'remaining_days' => 10]]]);

        $this->getJson("/api/v1/me/leave-balances?year={$nextYear}")
            ->assertOk()->assertJson(['data' => [['allocated_days' => 20, 'used_days' => 2, 'remaining_days' => 18]]]);
    }

    public function test_a_rejected_leave_request_never_consumes_balance(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leaveType = LeaveType::factory()->create();
        $year = Carbon::now()->addYear()->year;
        LeaveBalance::factory()->create(['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'allocated_days' => 5]);
        LeaveRequest::factory()->rejected()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::now()->addYear()->startOfYear()->addDays(1)->toDateString(),
            'end_date' => Carbon::now()->addYear()->startOfYear()->addDays(3)->toDateString(),
            'total_days' => 3,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/me/leave-balances?year={$year}")
            ->assertOk()
            ->assertJson(['data' => [['used_days' => 0, 'pending_days' => 0, 'remaining_days' => 5]]]);
    }

    public function test_a_manager_cannot_set_a_leave_balance_allocation(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $leaveType = LeaveType::factory()->create();
        Sanctum::actingAs($managerUser);

        $this->postJson("/api/v1/staff/{$report->public_id}/leave-balances", [
            'leave_type_id' => $leaveType->public_id, 'year' => 2027, 'allocated_days' => 10,
        ])->assertForbidden();
    }
}
