<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 11 Tasks endpoints
 * (CLAUDE.md §18): `tasks.view` (Manager-only, company-wide, mirroring
 * `projects.view`) and `tasks.manage` (Administrator-only) gate the
 * permission-based paths; a Project Lead may create/update Tasks scoped
 * to their own Project, a Task's assignee may update only its `status`,
 * and an ordinary Staff member with no permission is scoped to Tasks in
 * Projects they belong to plus Tasks assigned to them directly — all
 * enforced in-controller (AuthorizesTaskAccess), not by route middleware
 * alone. See docs/phases/V1_PHASE_11_DEFINITION.md.
 */
class TasksAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_every_task(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $task = Task::factory()->create();

        $this->getJson('/api/v1/tasks')->assertOk();
        $this->getJson("/api/v1/tasks/{$task->public_id}")->assertOk();
        $this->postJson('/api/v1/tasks', ['title' => 'New Task'])->assertCreated();
        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Renamed'])->assertOk();
        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertNoContent();
    }

    public function test_manager_can_view_every_task_but_not_manage_them(): void
    {
        $manager = User::factory()->manager()->create();
        Sanctum::actingAs($manager);
        $task = Task::factory()->create();

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/tasks/{$task->public_id}")->assertOk();
        $this->postJson('/api/v1/tasks', ['title' => 'New Task'])->assertForbidden();
        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertForbidden();
    }

    public function test_a_project_lead_can_manage_tasks_within_their_own_project(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($staff, 'staff')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', ['title' => 'Lead task', 'project_id' => $project->public_id])
            ->assertCreated();

        $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Existing']);

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Updated by lead'])
            ->assertOk()
            ->assertJson(['data' => ['title' => 'Updated by lead']]);
    }

    public function test_a_project_lead_cannot_manage_tasks_in_a_project_they_do_not_lead(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $leadProject = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($leadProject, 'project')->for($staff, 'staff')->create();
        $otherProject = Project::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', ['title' => 'Not allowed', 'project_id' => $otherProject->public_id])
            ->assertForbidden();

        $otherTask = Task::factory()->create(['project_id' => $otherProject->id]);
        $this->putJson("/api/v1/tasks/{$otherTask->public_id}", ['title' => 'Nope'])
            ->assertForbidden();
    }

    public function test_a_project_lead_cannot_create_or_manage_independent_tasks(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($staff, 'staff')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', ['title' => 'No project'])->assertForbidden();

        $independentTask = Task::factory()->create();
        $this->putJson("/api/v1/tasks/{$independentTask->public_id}", ['title' => 'Nope'])->assertForbidden();
    }

    public function test_an_ordinary_project_member_can_view_but_not_manage_project_tasks(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tasks/{$task->public_id}")->assertOk();
        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Nope'])->assertForbidden();
        $this->postJson('/api/v1/tasks', ['title' => 'Nope', 'project_id' => $project->public_id])->assertForbidden();
    }

    public function test_a_staff_non_member_cannot_view_a_projects_tasks(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tasks/{$task->public_id}")->assertForbidden();
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_assignee_can_view_and_update_only_the_status_of_their_own_independent_task(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $task = Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tasks/{$task->public_id}")->assertOk();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'in_progress']]);
    }

    public function test_an_assignee_cannot_update_fields_other_than_status(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $task = Task::factory()->create(['assignee_staff_id' => $staff->id, 'title' => 'Original']);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'in_progress', 'title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Original', $task->fresh()->title);
    }

    public function test_an_assignee_cannot_delete_their_own_task(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $task = Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertForbidden();
    }

    public function test_a_staff_member_with_no_project_membership_or_assignment_sees_no_tasks(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Task::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_with_no_role_and_no_linked_staff_cannot_view_any_tasks(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Task::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tasks')->assertForbidden();
        $this->postJson('/api/v1/tasks', ['title' => 'X'])->assertForbidden();
    }

    public function test_user_with_no_role_but_a_linked_staff_record_sees_only_their_scoped_tasks(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $myTask = Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Task::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $myTask->public_id]]]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $task = Task::factory()->create();

        $this->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->getJson("/api/v1/tasks/{$task->public_id}")->assertUnauthorized();
        $this->postJson('/api/v1/tasks', ['title' => 'New Task'])->assertUnauthorized();
    }

    public function test_suspended_account_loses_tasks_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks')
            ->assertForbidden();
    }
}
