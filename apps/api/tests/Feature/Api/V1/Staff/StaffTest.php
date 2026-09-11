<?php

namespace Tests\Feature\Api\V1\Staff;

use App\Enums\StaffStatus;
use App\Models\Department;
use App\Models\Position;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- CRUD ---------------------------------------------------------

    public function test_administrator_can_list_staff(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->count(2)->create();

        $this->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_administrator_can_create_a_staff_member(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'company_email' => 'ada@example.test',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'employee_number' => 'EMP-0001',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'display_name' => 'Ada Lovelace',
                    'status' => 'active',
                    'has_user_account' => false,
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('staff', ['employee_number' => 'EMP-0001']);
    }

    public function test_creating_a_staff_member_requires_employee_number_first_name_and_last_name(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/staff', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_number', 'first_name', 'last_name']);
    }

    public function test_employee_number_must_be_unique(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->create(['employee_number' => 'EMP-0001']);

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0001',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_number');
    }

    public function test_company_email_must_be_unique_when_present(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->create(['company_email' => 'taken@example.test']);

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0002',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'company_email' => 'taken@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('company_email');
    }

    public function test_administrator_can_view_a_staff_member_by_public_id(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->getJson("/api/v1/staff/{$staff->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['public_id' => $staff->public_id]]);
    }

    public function test_viewing_a_staff_member_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->getJson("/api/v1/staff/{$staff->id}")->assertNotFound();
    }

    public function test_administrator_can_update_a_staff_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['first_name' => 'Old']);

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['first_name' => 'New'])
            ->assertOk()
            ->assertJson(['data' => ['first_name' => 'New']]);
    }

    public function test_setting_status_to_separated_requires_a_separation_date(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['hire_date' => '2020-01-01']);

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['status' => 'separated'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('separation_date');
    }

    public function test_separation_date_must_not_precede_hire_date(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['hire_date' => '2024-06-01']);

        $this->putJson("/api/v1/staff/{$staff->public_id}", [
            'status' => 'separated',
            'separation_date' => '2024-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('separation_date');
    }

    public function test_administrator_can_separate_a_staff_member_with_a_valid_date(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['hire_date' => '2024-01-01']);

        $this->putJson("/api/v1/staff/{$staff->public_id}", [
            'status' => 'separated',
            'separation_date' => '2024-06-01',
        ])->assertOk()->assertJson(['data' => ['status' => 'separated']]);

        $this->assertSame(StaffStatus::Separated, $staff->fresh()->status);
    }

    public function test_administrator_can_delete_a_staff_member_with_no_direct_reports(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('staff', ['id' => $staff->id]);
    }

    public function test_deleting_a_staff_member_with_direct_reports_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $manager = Staff::factory()->create();
        Staff::factory()->create(['manager_id' => $manager->id]);

        $this->deleteJson("/api/v1/staff/{$manager->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('staff', ['id' => $manager->id]);
    }

    public function test_deleting_a_staff_member_with_project_memberships_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        ProjectMembership::factory()->for($staff, 'staff')->create();

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('staff', ['id' => $staff->id]);
    }

    // --- Filters / search ----------------------------------------------

    public function test_staff_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->create(['first_name' => 'Active One']);
        Staff::factory()->inactive()->create(['first_name' => 'Inactive One']);

        $this->getJson('/api/v1/staff?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'Inactive One']]]);
    }

    public function test_staff_can_be_filtered_by_department_team_and_position(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);
        $position = Position::factory()->create();

        Staff::factory()->create(['department_id' => $department->id, 'first_name' => 'In Dept']);
        Staff::factory()->create(['first_name' => 'Elsewhere']);

        $this->getJson("/api/v1/staff?department={$department->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'In Dept']]]);

        Staff::factory()->create(['team_id' => $team->id, 'first_name' => 'In Team']);
        $this->getJson("/api/v1/staff?team={$team->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'In Team']]]);

        Staff::factory()->create(['position_id' => $position->id, 'first_name' => 'In Position']);
        $this->getJson("/api/v1/staff?position={$position->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'In Position']]]);
    }

    public function test_staff_can_be_filtered_by_manager(): void
    {
        $this->actingAsAdministrator();
        $manager = Staff::factory()->create();
        Staff::factory()->create(['manager_id' => $manager->id, 'first_name' => 'Report']);
        Staff::factory()->create(['first_name' => 'Unrelated']);

        $this->getJson("/api/v1/staff?manager={$manager->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'Report']]]);
    }

    public function test_staff_can_be_searched_by_name_or_employee_number(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'employee_number' => 'EMP-1001']);
        Staff::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper', 'employee_number' => 'EMP-1002']);

        $this->getJson('/api/v1/staff?q=Lovelace')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['last_name' => 'Lovelace']]]);

        $this->getJson('/api/v1/staff?q=EMP-1002')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['employee_number' => 'EMP-1002']]]);
    }

    // --- Organization relationships -------------------------------------

    public function test_administrator_can_assign_department_team_and_position(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);
        $position = Position::factory()->create(['department_id' => $department->id]);

        $response = $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0003',
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'department_id' => $department->public_id,
            'team_id' => $team->public_id,
            'position_id' => $position->public_id,
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'department' => ['public_id' => $department->public_id],
                'team' => ['public_id' => $team->public_id],
                'position' => ['public_id' => $position->public_id],
            ],
        ]);

        // The internal numeric id is what's actually persisted — never
        // the public_id string (DEC-017).
        $this->assertDatabaseHas('staff', [
            'employee_number' => 'EMP-0003',
            'department_id' => $department->id,
            'team_id' => $team->id,
            'position_id' => $position->id,
        ]);
    }

    public function test_an_unknown_department_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0004',
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'department_id' => 'not-a-real-ulid',
        ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_an_unknown_team_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0005',
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'team_id' => 'not-a-real-ulid',
        ])->assertUnprocessable()->assertJsonValidationErrors('team_id');
    }

    public function test_department_is_auto_derived_from_team_when_omitted(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);

        $response = $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0006',
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'team_id' => $team->public_id,
        ]);

        $response->assertCreated()->assertJson([
            'data' => ['department' => ['public_id' => $department->public_id]],
        ]);
    }

    public function test_a_department_that_does_not_match_the_teams_department_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $teamDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $teamDepartment->id]);

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0007',
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'team_id' => $team->public_id,
            'department_id' => $otherDepartment->public_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_a_team_with_no_department_places_no_constraint_on_department_id(): void
    {
        $this->actingAsAdministrator();
        $team = Team::factory()->create(['department_id' => null]);
        $department = Department::factory()->create();

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-0008',
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'team_id' => $team->public_id,
            'department_id' => $department->public_id,
        ])->assertCreated();
    }

    // --- Manager relationship -------------------------------------------

    public function test_administrator_can_assign_a_manager(): void
    {
        $this->actingAsAdministrator();
        $manager = Staff::factory()->create();
        $staff = Staff::factory()->create();

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['manager_id' => $manager->public_id])
            ->assertOk()
            ->assertJson(['data' => ['manager' => ['public_id' => $manager->public_id]]]);

        $this->assertSame($manager->id, $staff->fresh()->manager_id);
    }

    public function test_an_unknown_manager_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['manager_id' => 'not-a-real-ulid'])
            ->assertUnprocessable()->assertJsonValidationErrors('manager_id');
    }

    public function test_a_staff_member_cannot_be_their_own_manager(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['manager_id' => $staff->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('manager_id');
    }

    public function test_a_manager_assignment_that_would_create_a_reporting_cycle_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $a = Staff::factory()->create();
        $b = Staff::factory()->create(['manager_id' => $a->id]);

        // Attempting to make A report to B, when B already reports to A,
        // would create a two-node cycle.
        $this->putJson("/api/v1/staff/{$a->public_id}", ['manager_id' => $b->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('manager_id');
    }

    // --- User relationship ------------------------------------------------

    public function test_a_staff_member_can_exist_without_a_linked_user(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['user_id' => null]);

        $this->getJson("/api/v1/staff/{$staff->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['has_user_account' => false]]);
    }

    public function test_administrator_can_link_a_staff_member_to_a_user(): void
    {
        $this->actingAsAdministrator();
        $user = User::factory()->staff()->create();
        $staffMember = Staff::factory()->create();

        $this->putJson("/api/v1/staff/{$staffMember->public_id}", ['user_id' => $user->public_id])
            ->assertOk()
            ->assertJson([
                'data' => [
                    'has_user_account' => true,
                    'user' => ['public_id' => $user->public_id, 'email' => $user->email],
                ],
            ]);

        $this->assertSame($user->id, $staffMember->fresh()->user_id);
    }

    public function test_a_user_cannot_be_linked_to_more_than_one_staff_member(): void
    {
        $this->actingAsAdministrator();
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $secondStaff = Staff::factory()->create();

        $this->putJson("/api/v1/staff/{$secondStaff->public_id}", ['user_id' => $user->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_the_linked_user_identity_is_only_visible_to_a_staff_manage_holder(): void
    {
        $user = User::factory()->staff()->create();
        $staffMember = Staff::factory()->create(['user_id' => $user->id]);

        // A Manager holds staff.view (directory) but not staff.manage —
        // only real once the permission catalog is actually seeded.
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->manager()->create();
        Sanctum::actingAs($manager);

        $response = $this->getJson("/api/v1/staff/{$staffMember->public_id}");

        $response->assertOk()
            ->assertJson(['data' => ['has_user_account' => true]])
            ->assertJsonMissingPath('data.user');
    }

    public function test_the_staff_directory_reflects_current_operational_status(): void
    {
        $this->actingAsAdministrator();
        $staffMember = Staff::factory()->create();

        $this->getJson("/api/v1/staff/{$staffMember->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['operational_status' => null]]);

        $staffMember->operationalStatuses()->create(['status' => 'in_field']);

        $this->getJson("/api/v1/staff/{$staffMember->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['operational_status' => 'in_field']]);
    }
}
