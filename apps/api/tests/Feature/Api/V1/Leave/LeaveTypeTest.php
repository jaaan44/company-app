<?php

namespace Tests\Feature\Api\V1\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveTypeTest extends TestCase
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

    public function test_administrator_can_create_a_leave_type(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/leave-types', [
            'name' => 'Vacation',
            'code' => 'VAC',
            'is_paid' => true,
        ]);

        $response->assertCreated()->assertJson([
            'data' => ['name' => 'Vacation', 'code' => 'VAC', 'is_paid' => true, 'status' => 'active'],
        ])->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('leave_types', ['name' => 'Vacation']);
    }

    public function test_creating_a_leave_type_requires_a_unique_name(): void
    {
        $this->actingAsAdministrator();
        LeaveType::factory()->create(['name' => 'Sick']);

        $this->postJson('/api/v1/leave-types', ['name' => 'Sick'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_leave_type_defaults_to_paid_and_active(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/leave-types', ['name' => 'Bereavement'])
            ->assertCreated()->assertJson(['data' => ['is_paid' => true, 'status' => 'active']]);
    }

    public function test_administrator_can_create_an_unpaid_leave_type(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/leave-types', ['name' => 'Unpaid Leave', 'is_paid' => false])
            ->assertCreated()->assertJson(['data' => ['is_paid' => false]]);
    }

    public function test_administrator_can_update_a_leave_type(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create(['name' => 'Old Name']);

        $this->putJson("/api/v1/leave-types/{$leaveType->public_id}", ['name' => 'New Name'])
            ->assertOk()->assertJson(['data' => ['name' => 'New Name']]);
    }

    public function test_administrator_can_deactivate_a_leave_type(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();

        $this->putJson("/api/v1/leave-types/{$leaveType->public_id}", ['status' => 'inactive'])
            ->assertOk()->assertJson(['data' => ['status' => 'inactive']]);
    }

    public function test_staff_can_list_active_leave_types_to_submit_a_request(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        LeaveType::factory()->create();
        LeaveType::factory()->inactive()->create();

        $this->getJson('/api/v1/leave-types')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/leave-types?status=active')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_staff_cannot_manage_leave_types(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/leave-types', ['name' => 'X'])->assertForbidden();
    }

    public function test_administrator_can_delete_an_unreferenced_leave_type(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();

        $this->deleteJson("/api/v1/leave-types/{$leaveType->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('leave_types', ['id' => $leaveType->id]);
    }

    public function test_deleting_a_leave_type_with_leave_requests_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();
        LeaveRequest::factory()->create(['leave_type_id' => $leaveType->id]);

        $this->deleteJson("/api/v1/leave-types/{$leaveType->public_id}")->assertStatus(409);
        $this->assertDatabaseHas('leave_types', ['id' => $leaveType->id]);
    }

    public function test_deleting_a_leave_type_with_leave_balances_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();
        LeaveBalance::factory()->create(['leave_type_id' => $leaveType->id]);

        $this->deleteJson("/api/v1/leave-types/{$leaveType->public_id}")->assertStatus(409);
        $this->assertDatabaseHas('leave_types', ['id' => $leaveType->id]);
    }

    public function test_a_deactivated_leave_type_preserves_its_existing_leave_requests(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['leave_type_id' => $leaveType->id]);

        $this->putJson("/api/v1/leave-types/{$leaveType->public_id}", ['status' => 'inactive'])->assertOk();

        $this->getJson("/api/v1/leave-requests/{$leaveRequest->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['leave_type' => ['public_id' => $leaveType->public_id]]]);
    }

    public function test_viewing_a_leave_type_by_its_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();

        $this->getJson("/api/v1/leave-types/{$leaveType->id}")->assertNotFound();
    }

    // --- Staff deletion protection ---------------------------------------

    public function test_a_staff_member_with_leave_requests_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        LeaveRequest::factory()->create(['staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }

    public function test_a_staff_member_with_leave_balances_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        LeaveBalance::factory()->create(['staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }
}
