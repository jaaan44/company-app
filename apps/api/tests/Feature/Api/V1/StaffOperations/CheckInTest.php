<?php

namespace Tests\Feature\Api\V1\StaffOperations;

use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInTest extends TestCase
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

    // --- Self-service check-in ------------------------------------------

    public function test_a_staff_linked_user_can_check_in(): void
    {
        [, $staffMember] = $this->actingAsStaffUser();

        $response = $this->postJson('/api/v1/me/check-ins', [
            'latitude' => 51.5072,
            'longitude' => -0.1276,
            'accuracy_meters' => 12,
            'location_label' => 'Client Site',
            'note' => 'Visiting Acme HQ',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'latitude' => 51.5072,
                    'longitude' => -0.1276,
                    'accuracy_meters' => 12,
                    'location_label' => 'Client Site',
                    'note' => 'Visiting Acme HQ',
                ],
            ])
            ->assertJsonStructure(['data' => ['public_id', 'checked_in_at']])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.staff_id');

        $this->assertDatabaseHas('staff_checkins', ['staff_id' => $staffMember->id]);
    }

    public function test_check_in_accepts_an_optional_operational_status_snapshot(): void
    {
        [, $staffMember] = $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', [
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'in_field',
        ])->assertCreated()->assertJson(['data' => ['status' => 'in_field']]);

        // The check-in status snapshot is informational only — it must not
        // also write a staff_statuses history entry.
        $this->assertSame(0, $staffMember->fresh()->operationalStatuses()->count());
    }

    public function test_latitude_must_be_within_valid_range(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', ['latitude' => 200, 'longitude' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');
    }

    public function test_longitude_must_be_within_valid_range(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', ['latitude' => 0, 'longitude' => -200])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');
    }

    public function test_latitude_and_longitude_are_required(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_an_invalid_operational_status_snapshot_is_rejected(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', [
            'latitude' => 0,
            'longitude' => 0,
            'status' => 'on_a_beach',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_note_has_a_reasonable_length_limit(): void
    {
        $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', [
            'latitude' => 0,
            'longitude' => 0,
            'note' => str_repeat('a', 501),
        ])->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_multiple_check_ins_build_a_history_with_the_latest_first(): void
    {
        [, $staffMember] = $this->actingAsStaffUser();

        $this->postJson('/api/v1/me/check-ins', ['latitude' => 1, 'longitude' => 1, 'location_label' => 'Office'])->assertCreated();
        $this->postJson('/api/v1/me/check-ins', ['latitude' => 2, 'longitude' => 2, 'location_label' => 'Client Site'])->assertCreated();

        $response = $this->getJson('/api/v1/me/check-ins')->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertSame('Client Site', $response->json('data.0.location_label'));
        $this->assertSame('Office', $response->json('data.1.location_label'));
    }

    public function test_a_user_with_no_linked_staff_record_cannot_check_in_or_view_history(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/check-ins')->assertForbidden();
        $this->postJson('/api/v1/me/check-ins', ['latitude' => 0, 'longitude' => 0])->assertForbidden();
    }

    // --- Privileged viewing ----------------------------------------------

    public function test_administrator_can_view_any_staff_members_check_in_history(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $other = Staff::factory()->create();
        StaffCheckIn::factory()->for($other, 'staff')->create();

        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_manager_can_view_a_direct_reports_check_in_history(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        StaffCheckIn::factory()->for($report, 'staff')->create();
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$report->public_id}/check-ins")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_manager_cannot_view_a_non_reports_check_in_history(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $unrelated = Staff::factory()->create();
        StaffCheckIn::factory()->for($unrelated, 'staff')->create();
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$unrelated->public_id}/check-ins")->assertForbidden();
    }

    public function test_manager_with_no_linked_staff_record_cannot_view_anyones_check_in_history(): void
    {
        $managerUser = User::factory()->manager()->create();
        $other = Staff::factory()->create();
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertForbidden();
    }

    public function test_staff_cannot_view_another_staff_members_check_in_history(): void
    {
        $this->actingAsStaffUser();
        $other = Staff::factory()->create();
        StaffCheckIn::factory()->for($other, 'staff')->create();

        $this->getJson("/api/v1/staff/{$other->public_id}/check-ins")->assertForbidden();
    }

    // --- Deletion / correction --------------------------------------------

    public function test_administrator_can_delete_a_check_in(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $checkIn = StaffCheckIn::factory()->create();

        $this->deleteJson("/api/v1/check-ins/{$checkIn->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('staff_checkins', ['id' => $checkIn->id]);
    }

    public function test_non_administrator_cannot_delete_a_check_in(): void
    {
        [, $staffMember] = $this->actingAsStaffUser();
        $checkIn = StaffCheckIn::factory()->for($staffMember, 'staff')->create();

        $this->deleteJson("/api/v1/check-ins/{$checkIn->public_id}")->assertForbidden();
    }

    public function test_a_check_in_is_never_addressed_by_its_internal_numeric_id(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $checkIn = StaffCheckIn::factory()->create();

        $this->deleteJson("/api/v1/check-ins/{$checkIn->id}")->assertNotFound();
    }
}
