<?php

namespace Tests\Feature\Api\V1\Projects;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- CRUD -------------------------------------------------------------

    public function test_administrator_can_create_a_milestone(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->public_id}/milestones", [
            'title' => 'Design sign-off',
            'due_date' => '2026-11-01',
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'title' => 'Design sign-off',
                'due_date' => '2026-11-01',
                'status' => 'pending',
                'project' => ['public_id' => $project->public_id],
            ],
        ])->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('project_milestones', ['title' => 'Design sign-off', 'project_id' => $project->id]);
    }

    public function test_creating_a_milestone_requires_a_title_and_due_date(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'due_date']);
    }

    #[DataProvider('statusProvider')]
    public function test_each_status_value_is_accepted(string $status): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", [
            'title' => 'X', 'due_date' => '2026-11-01', 'status' => $status,
        ])->assertCreated()->assertJson(['data' => ['status' => $status]]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", [
            'title' => 'X', 'due_date' => '2026-11-01', 'status' => 'not-a-status',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_administrator_can_view_update_and_delete_a_milestone(): void
    {
        $this->actingAsAdministrator();
        $milestone = ProjectMilestone::factory()->create();
        $project = $milestone->project;

        $this->getJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}")->assertOk();

        $this->putJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}", ['status' => 'completed'])
            ->assertOk()->assertJson(['data' => ['status' => 'completed']]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('project_milestones', ['id' => $milestone->id]);
    }

    public function test_viewing_a_milestone_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $milestone = ProjectMilestone::factory()->create();

        $this->getJson("/api/v1/projects/{$milestone->project->public_id}/milestones/{$milestone->id}")->assertNotFound();
    }

    public function test_a_milestone_addressed_through_a_different_project_is_not_found(): void
    {
        $this->actingAsAdministrator();
        $milestone = ProjectMilestone::factory()->create();
        $otherProject = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$otherProject->public_id}/milestones/{$milestone->public_id}")->assertNotFound();
    }

    // --- Authorization ------------------------------------------------------

    public function test_a_current_project_member_can_view_milestones(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/projects/{$project->public_id}/milestones")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}")->assertOk();
    }

    public function test_an_unrelated_staff_member_cannot_view_milestones(): void
    {
        $project = Project::factory()->create();
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/projects/{$project->public_id}/milestones")->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}")->assertForbidden();
    }

    public function test_a_project_lead_can_manage_milestones_for_their_project(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->projectLead()->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", ['title' => 'X', 'due_date' => '2026-11-01'])
            ->assertCreated();

        $milestone = ProjectMilestone::query()->where('project_id', $project->id)->firstOrFail();

        $this->putJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}", ['status' => 'completed'])
            ->assertOk();
        $this->deleteJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}")->assertNoContent();
    }

    public function test_an_ordinary_project_member_cannot_manage_milestones(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", ['title' => 'X', 'due_date' => '2026-11-01'])
            ->assertForbidden();
        $this->putJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}", ['status' => 'completed'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}")->assertForbidden();
    }

    public function test_a_manager_holding_projects_view_can_view_but_not_manage_milestones(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $managerUser = User::factory()->manager()->create();
        $project = Project::factory()->create();
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id]);

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/projects/{$project->public_id}/milestones")->assertOk();
        $this->putJson("/api/v1/projects/{$project->public_id}/milestones/{$milestone->public_id}", ['status' => 'completed'])
            ->assertForbidden();
    }

    // --- Filters --------------------------------------------------------

    public function test_milestones_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'title' => 'Pending one']);
        ProjectMilestone::factory()->completed()->create(['project_id' => $project->id, 'title' => 'Done one']);

        $this->getJson("/api/v1/projects/{$project->public_id}/milestones?status=completed")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Done one']]]);
    }

    // --- Relational integrity ---------------------------------------------

    public function test_a_project_with_milestones_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $milestone = ProjectMilestone::factory()->create();

        $this->deleteJson("/api/v1/projects/{$milestone->project->public_id}")->assertStatus(409);
    }
}
