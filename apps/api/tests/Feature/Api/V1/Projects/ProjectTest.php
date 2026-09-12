<?php

namespace Tests\Feature\Api\V1\Projects;

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- CRUD ---------------------------------------------------------

    public function test_administrator_can_list_projects(): void
    {
        $this->actingAsAdministrator();
        Project::factory()->count(2)->create();

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_administrator_can_create_a_project(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/projects', [
            'project_code' => 'PRJ-0001',
            'name' => 'Website Rebuild',
            'description' => 'Rebuild the marketing website.',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'project_code' => 'PRJ-0001',
                    'name' => 'Website Rebuild',
                    'status' => 'planned',
                    'client' => null,
                    'members_count' => 0,
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('projects', ['name' => 'Website Rebuild']);
    }

    public function test_creating_a_project_requires_a_name(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/projects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_project_code_must_be_unique_when_present(): void
    {
        $this->actingAsAdministrator();
        Project::factory()->create(['project_code' => 'PRJ-0001']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Another Project',
            'project_code' => 'PRJ-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_code');
    }

    public function test_a_project_can_be_created_without_a_project_code_or_client(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/projects', ['name' => 'Internal Initiative'])
            ->assertCreated()
            ->assertJson(['data' => ['project_code' => null, 'client' => null]]);
    }

    public function test_administrator_can_view_a_project_by_public_id(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['public_id' => $project->public_id]]);
    }

    public function test_viewing_a_project_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    }

    public function test_administrator_can_update_a_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create(['name' => 'Old Name']);

        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJson(['data' => ['name' => 'New Name']]);
    }

    public function test_administrator_can_delete_a_project_with_no_members(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_project_with_members_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->create();

        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_project_with_tasks_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    // --- Client relationship ----------------------------------------------

    public function test_a_project_can_be_created_with_a_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/projects', [
            'name' => 'Client Engagement',
            'client_id' => $client->public_id,
        ])->assertCreated()->assertJson([
            'data' => ['client' => ['public_id' => $client->public_id, 'name' => $client->name]],
        ]);
    }

    public function test_an_unknown_client_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/projects', [
            'name' => 'Client Engagement',
            'client_id' => 'not-a-real-ulid',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_a_project_may_be_created_for_an_inactive_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->inactive()->create();

        $this->postJson('/api/v1/projects', [
            'name' => 'Renewed Engagement',
            'client_id' => $client->public_id,
        ])->assertCreated();
    }

    // --- Lifecycle ----------------------------------------------------

    public function test_administrator_can_change_a_projects_status(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->putJson("/api/v1/projects/{$project->public_id}", ['status' => 'active'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'active']]);

        $this->assertSame(ProjectStatus::Active, $project->fresh()->status);
    }

    public function test_completing_a_project_requires_a_completed_date(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->putJson("/api/v1/projects/{$project->public_id}", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('completed_date');

        $this->putJson("/api/v1/projects/{$project->public_id}", [
            'status' => 'completed',
            'completed_date' => now()->toDateString(),
        ])->assertOk()->assertJson(['data' => ['status' => 'completed']]);
    }

    public function test_a_completed_project_can_be_reopened(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->completed()->create();

        $this->putJson("/api/v1/projects/{$project->public_id}", ['status' => 'active'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'active']]);
    }

    public function test_target_end_date_must_not_precede_start_date(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/projects', [
            'name' => 'Bad Dates',
            'start_date' => '2026-06-01',
            'target_end_date' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_end_date');
    }

    // --- Filters / search ------------------------------------------------

    public function test_projects_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        Project::factory()->create(['name' => 'Planned One']);
        Project::factory()->active()->create(['name' => 'Active One']);

        $this->getJson('/api/v1/projects?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Active One']]]);
    }

    public function test_projects_can_be_filtered_by_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        Project::factory()->create(['client_id' => $client->id, 'name' => 'Belongs']);
        Project::factory()->create(['name' => 'Elsewhere']);

        $this->getJson("/api/v1/projects?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Belongs']]]);
    }

    public function test_projects_can_be_filtered_by_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $project = Project::factory()->create(['name' => 'Has Member']);
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        Project::factory()->create(['name' => 'No Member']);

        $this->getJson("/api/v1/projects?member={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Has Member']]]);
    }

    public function test_projects_can_be_searched_by_name_or_project_code(): void
    {
        $this->actingAsAdministrator();
        Project::factory()->create(['name' => 'Alpha Rollout', 'project_code' => 'PRJ-1001']);
        Project::factory()->create(['name' => 'Beta Rollout', 'project_code' => 'PRJ-1002']);

        $this->getJson('/api/v1/projects?q=Alpha')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Alpha Rollout']]]);

        $this->getJson('/api/v1/projects?q=PRJ-1002')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['project_code' => 'PRJ-1002']]]);
    }

    public function test_projects_report_their_member_count(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->count(3)->create();

        $this->getJson("/api/v1/projects/{$project->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['members_count' => 3]]);
    }

    public function test_project_response_includes_the_requesters_own_membership_role(): void
    {
        $user = User::factory()->administrator()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($staff, 'staff')->create();

        $this->getJson("/api/v1/projects/{$project->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['my_role' => 'project_lead']]);
    }
}
