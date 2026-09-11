<?php

namespace Tests\Feature\Api\V1\Projects;

use App\Enums\ProjectMembershipRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_administrator_can_list_project_members(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->count(2)->create();

        $this->getJson("/api/v1/projects/{$project->public_id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.staff.id');
    }

    public function test_administrator_can_add_a_member_to_a_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->public_id}/members", [
            'staff_id' => $staff->public_id,
            'role' => 'project_lead',
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'staff' => ['public_id' => $staff->public_id, 'employee_number' => $staff->employee_number],
                'role' => 'project_lead',
            ],
        ]);

        $this->assertDatabaseHas('project_memberships', ['project_id' => $project->id, 'staff_id' => $staff->id]);
    }

    public function test_adding_a_member_defaults_to_the_member_role(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $staff->public_id])
            ->assertCreated()
            ->assertJson(['data' => ['role' => 'member']]);
    }

    public function test_adding_a_member_requires_a_staff_id(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/members", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_an_unknown_staff_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => 'not-a-real-ulid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_adding_to_an_unknown_project_public_id_fails(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/projects/not-a-real-ulid/members', ['staff_id' => $staff->public_id])
            ->assertNotFound();
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $staff->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_only_active_staff_may_be_newly_assigned_to_a_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $inactiveStaff = Staff::factory()->inactive()->create();
        $separatedStaff = Staff::factory()->separated()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $inactiveStaff->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');

        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $separatedStaff->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_an_existing_membership_survives_the_staff_member_later_becoming_inactive(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $staff->update(['status' => 'inactive']);

        $this->getJson("/api/v1/projects/{$project->public_id}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_administrator_can_change_a_members_role(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->putJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}", ['role' => 'project_lead'])
            ->assertOk()
            ->assertJson(['data' => ['role' => 'project_lead']]);

        $this->assertSame(
            ProjectMembershipRole::ProjectLead,
            ProjectMembership::query()->where('project_id', $project->id)->where('staff_id', $staff->id)->first()->role,
        );
    }

    public function test_updating_a_non_members_role_fails(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();

        $this->putJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}", ['role' => 'project_lead'])
            ->assertNotFound();
    }

    public function test_administrator_can_remove_a_member(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('project_memberships', ['project_id' => $project->id, 'staff_id' => $staff->id]);
    }

    public function test_members_can_be_filtered_by_role(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->create();
        ProjectMembership::factory()->for($project, 'project')->create();

        $this->getJson("/api/v1/projects/{$project->public_id}/members?role=project_lead")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['role' => 'project_lead']]]);
    }
}
