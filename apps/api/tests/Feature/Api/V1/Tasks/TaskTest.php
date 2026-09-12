<?php

namespace Tests\Feature\Api\V1\Tasks;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- CRUD ---------------------------------------------------------

    public function test_administrator_can_list_tasks(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->count(2)->create();

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_administrator_can_create_an_independent_task(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/tasks', [
            'title' => 'Renew office lease',
            'description' => 'Contact the landlord about renewal terms.',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'title' => 'Renew office lease',
                    'status' => 'todo',
                    'priority' => 'normal',
                    'project' => null,
                    'assignee' => null,
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('tasks', ['title' => 'Renew office lease', 'project_id' => null]);
    }

    public function test_creating_a_task_requires_a_title(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_administrator_can_create_a_task_within_a_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Draft proposal',
            'project_id' => $project->public_id,
        ])->assertCreated()->assertJson([
            'data' => ['project' => ['public_id' => $project->public_id, 'name' => $project->name]],
        ]);
    }

    public function test_an_unknown_project_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Draft proposal',
            'project_id' => 'not-a-real-ulid',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_administrator_can_view_a_task_by_public_id(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->getJson("/api/v1/tasks/{$task->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['public_id' => $task->public_id]]);
    }

    public function test_viewing_a_task_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
    }

    public function test_administrator_can_update_a_task(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create(['title' => 'Old Title']);

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJson(['data' => ['title' => 'New Title']]);
    }

    public function test_project_id_cannot_be_changed_on_update(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $task = Task::factory()->create();
        $otherProject = Project::factory()->create();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['project_id' => $otherProject->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');
    }

    public function test_completed_at_cannot_be_client_supplied(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->postJson('/api/v1/tasks', ['title' => 'X', 'completed_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('completed_at');

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['completed_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('completed_at');
    }

    public function test_administrator_can_delete_a_todo_task(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_deleting_a_task_that_is_not_todo_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->inProgress()->create();

        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_a_completed_task_cannot_be_hard_deleted(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->completed()->create();

        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertStatus(409);
    }

    // --- Lifecycle ------------------------------------------------------

    public function test_administrator_can_change_a_tasks_status(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'in_progress']]);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'not-a-status'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_completing_a_task_sets_completed_at_automatically(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'completed']]);

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_reopening_a_completed_task_clears_completed_at(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->completed()->create();
        $this->assertNotNull($task->completed_at);

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'in_progress', 'completed_at' => null]]);

        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_an_invalid_priority_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', ['title' => 'X', 'priority' => 'not-a-priority'])
            ->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    // --- Assignment -------------------------------------------------------

    public function test_a_task_can_be_created_unassigned(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', ['title' => 'Unassigned'])
            ->assertCreated()->assertJson(['data' => ['assignee' => null]]);
    }

    public function test_an_independent_task_can_be_assigned_to_any_active_staff_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/tasks', ['title' => 'Do a thing', 'assignee_staff_id' => $staff->public_id])
            ->assertCreated()
            ->assertJson(['data' => ['assignee' => ['public_id' => $staff->public_id]]]);
    }

    public function test_an_unknown_assignee_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', ['title' => 'X', 'assignee_staff_id' => 'not-a-real-ulid'])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_staff_id');
    }

    public function test_an_inactive_or_separated_staff_member_cannot_be_newly_assigned(): void
    {
        $this->actingAsAdministrator();
        $inactive = Staff::factory()->inactive()->create();
        $separated = Staff::factory()->separated()->create();

        $this->postJson('/api/v1/tasks', ['title' => 'X', 'assignee_staff_id' => $inactive->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_staff_id');

        $this->postJson('/api/v1/tasks', ['title' => 'Y', 'assignee_staff_id' => $separated->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_staff_id');
    }

    public function test_a_project_tasks_assignee_must_be_a_member_of_that_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $nonMember = Staff::factory()->create();

        $this->postJson('/api/v1/tasks', [
            'title' => 'X',
            'project_id' => $project->public_id,
            'assignee_staff_id' => $nonMember->public_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_staff_id');

        $member = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($member, 'staff')->create();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Y',
            'project_id' => $project->public_id,
            'assignee_staff_id' => $member->public_id,
        ])->assertCreated();
    }

    public function test_administrator_can_reassign_a_task(): void
    {
        $this->actingAsAdministrator();
        $original = Staff::factory()->create();
        $replacement = Staff::factory()->create();
        $task = Task::factory()->create(['assignee_staff_id' => $original->id]);

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['assignee_staff_id' => $replacement->public_id])
            ->assertOk()
            ->assertJson(['data' => ['assignee' => ['public_id' => $replacement->public_id]]]);
    }

    public function test_a_task_can_be_unassigned(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $task = Task::factory()->create(['assignee_staff_id' => $staff->id]);

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['assignee_staff_id' => null])
            ->assertOk()
            ->assertJson(['data' => ['assignee' => null]]);
    }

    public function test_removing_a_project_membership_does_not_disturb_an_existing_task_assignment(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'assignee_staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}")
            ->assertNoContent();

        $this->assertSame($staff->id, $task->fresh()->assignee_staff_id);
    }

    public function test_an_existing_assignment_survives_the_staff_member_later_becoming_inactive(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $task = Task::factory()->create(['assignee_staff_id' => $staff->id]);

        $staff->update(['status' => 'inactive']);

        $this->getJson("/api/v1/tasks/{$task->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['assignee' => ['public_id' => $staff->public_id]]]);
    }

    // --- Filters / search ------------------------------------------------

    public function test_tasks_can_be_filtered_by_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'title' => 'Belongs']);
        Task::factory()->create(['title' => 'Elsewhere']);

        $this->getJson("/api/v1/tasks?project={$project->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Belongs']]]);
    }

    public function test_tasks_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['title' => 'Todo One']);
        Task::factory()->inProgress()->create(['title' => 'In Progress One']);

        $this->getJson('/api/v1/tasks?status=in_progress')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'In Progress One']]]);
    }

    public function test_tasks_can_be_filtered_by_assignee(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        Task::factory()->create(['assignee_staff_id' => $staff->id, 'title' => 'Assigned']);
        Task::factory()->create(['title' => 'Unassigned']);

        $this->getJson("/api/v1/tasks?assignee={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Assigned']]]);
    }

    public function test_tasks_can_be_filtered_by_priority(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['title' => 'Normal One']);
        Task::factory()->urgent()->create(['title' => 'Urgent One']);

        $this->getJson('/api/v1/tasks?priority=urgent')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Urgent One']]]);
    }

    public function test_tasks_can_be_searched_by_title(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['title' => 'Renew the domain']);
        Task::factory()->create(['title' => 'Order new laptops']);

        $this->getJson('/api/v1/tasks?q=domain')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Renew the domain']]]);
    }

    // --- Creator ----------------------------------------------------------

    public function test_task_resource_reports_the_creators_staff_identity_when_linked(): void
    {
        $admin = User::factory()->administrator()->create();
        $creatorStaff = Staff::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/tasks', ['title' => 'Created by admin']);

        $response->assertCreated()->assertJson([
            'data' => ['created_by' => ['public_id' => $creatorStaff->public_id]],
        ]);
    }

    public function test_task_resource_reports_no_creator_when_creator_has_no_linked_staff(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', ['title' => 'Created by unlinked admin'])
            ->assertCreated()
            ->assertJson(['data' => ['created_by' => null]]);
    }
}
