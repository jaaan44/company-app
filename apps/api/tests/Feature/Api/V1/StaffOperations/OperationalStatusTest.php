<?php

namespace Tests\Feature\Api\V1\StaffOperations;

use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsStaffUser(): array
    {
        $user = User::factory()->staff()->create();
        $staffMember = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return [$user, $staffMember];
    }

    // --- Self-service ---------------------------------------------------

    public function test_a_newly_linked_staff_member_has_no_current_status(): void
    {
        $this->actingAsStaffUser();

        $this->getJson('/api/v1/me/status')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_staff_linked_user_can_set_their_own_operational_status(): void
    {
        [, $staffMember] = $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/status', ['status' => 'in_field'])
            ->assertCreated()
            ->assertJson(['data' => ['status' => 'in_field']]);

        $this->assertDatabaseHas('staff_statuses', [
            'staff_id' => $staffMember->id,
            'status' => 'in_field',
        ]);
    }

    public function test_the_most_recently_set_status_is_returned_first(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/status', ['status' => 'available'])->assertCreated();
        $this->postJson('/api/v1/me/status', ['status' => 'busy'])->assertCreated();
        $this->postJson('/api/v1/me/status', ['status' => 'off_duty'])->assertCreated();

        $response = $this->getJson('/api/v1/me/status')->assertOk();
        $response->assertJsonCount(3, 'data');
        $this->assertSame('off_duty', $response->json('data.0.status'));
        $this->assertSame('busy', $response->json('data.1.status'));
        $this->assertSame('available', $response->json('data.2.status'));
    }

    public function test_an_invalid_status_value_is_rejected(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/status', ['status' => 'on_a_beach'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_a_missing_status_value_is_rejected(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/status', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_setting_operational_status_never_changes_employment_status(): void
    {
        [, $staffMember] = $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/status', ['status' => 'off_duty'])->assertCreated();

        $this->assertSame(StaffStatus::Active, $staffMember->fresh()->status);
    }

    public function test_a_user_with_no_linked_staff_record_cannot_view_or_set_status(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/status')->assertForbidden();
        $this->postJson('/api/v1/me/status', ['status' => 'available'])->assertForbidden();
    }

    // --- Viewing another staff member's status --------------------------

    public function test_staff_can_view_another_staff_members_current_status(): void
    {
        [, $viewerStaff] = $this->actingAsStaffUser();
        $other = Staff::factory()->create();
        $other->operationalStatuses()->create(['status' => 'busy']);

        $this->getJson("/api/v1/staff/{$other->public_id}/status")
            ->assertOk()
            ->assertJson(['data' => [['status' => 'busy']]]);
    }

    public function test_staff_cannot_set_another_staff_members_status(): void
    {
        $this->actingAsStaffUser();
        $other = Staff::factory()->create();

        $this->postJson("/api/v1/staff/{$other->public_id}/status", ['status' => 'busy'])
            ->assertForbidden();
    }

    public function test_administrator_can_set_another_staff_members_status_as_a_correction(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $other = Staff::factory()->create();

        $this->postJson("/api/v1/staff/{$other->public_id}/status", ['status' => 'off_duty'])
            ->assertCreated()
            ->assertJson(['data' => ['status' => 'off_duty']]);

        $this->assertDatabaseHas('staff_statuses', [
            'staff_id' => $other->id,
            'status' => 'off_duty',
            'changed_by_user_id' => $admin->id,
        ]);
    }

    public function test_manager_can_view_but_not_set_another_staff_members_status(): void
    {
        $user = User::factory()->manager()->create();
        Sanctum::actingAs($user);
        $other = Staff::factory()->create();

        $this->getJson("/api/v1/staff/{$other->public_id}/status")->assertOk();
        $this->postJson("/api/v1/staff/{$other->public_id}/status", ['status' => 'busy'])->assertForbidden();
    }
}
