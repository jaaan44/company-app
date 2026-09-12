<?php

namespace Tests\Feature\Api\V1\WorkLogs;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkLogTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsLinkedStaff(): Staff
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $staff;
    }

    // --- Self-service creation: Project-linked work ------------------------

    public function test_a_project_member_can_log_work_directly_against_the_project(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 60,
            'description' => 'Attended project kickoff meeting.',
        ])->assertCreated()->assertJson([
            'data' => [
                'project' => ['public_id' => $project->public_id],
                'task' => null,
                'duration_minutes' => 60,
            ],
        ])->assertJsonMissingPath('data.id');
    }

    public function test_a_non_member_cannot_log_work_against_a_project(): void
    {
        $this->actingAsLinkedStaff();
        $project = Project::factory()->create();

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 60,
            'description' => 'Should be rejected.',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    // --- Self-service creation: Task-linked work ----------------------------

    public function test_any_project_member_can_log_work_against_a_project_linked_task_not_just_the_assignee(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $otherAssignee = Staff::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'assignee_staff_id' => $otherAssignee->id]);

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => $task->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Helped out on this task.',
        ])->assertCreated()->assertJson([
            'data' => ['task' => ['public_id' => $task->public_id], 'project' => ['public_id' => $project->public_id]],
        ]);
    }

    public function test_a_non_member_cannot_log_work_against_a_project_linked_task(): void
    {
        $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => $task->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Should be rejected.',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_the_assignee_of_an_independent_task_can_log_work_against_it(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $task = Task::factory()->create(['assignee_staff_id' => $staff->id]);

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => $task->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 45,
            'description' => 'Internal task work.',
        ])->assertCreated()->assertJson([
            'data' => ['task' => ['public_id' => $task->public_id], 'project' => null],
        ]);
    }

    public function test_a_staff_member_who_is_not_the_assignee_cannot_log_work_against_an_independent_task(): void
    {
        $this->actingAsLinkedStaff();
        $task = Task::factory()->create();

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => $task->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 45,
            'description' => 'Should be rejected.',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    // --- Task/Project consistency ------------------------------------------

    public function test_a_work_log_must_reference_a_task_or_a_project(): void
    {
        $this->actingAsLinkedStaff();

        $this->postJson('/api/v1/me/work-logs', [
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Neither task nor project.',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_a_client_supplied_project_id_alongside_a_task_id_is_rejected(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $otherProject = Project::factory()->create();

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => $task->public_id,
            'project_id' => $otherProject->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Inconsistent.',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    // --- Eligibility ---------------------------------------------------------

    public function test_an_inactive_staff_member_cannot_create_a_new_work_log(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->inactive()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Should be rejected.',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_a_separated_staff_member_cannot_create_a_new_work_log(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->separated()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Should be rejected.',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_a_user_with_no_linked_staff_record_cannot_use_self_service(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/work-logs')->assertForbidden();
        $this->postJson('/api/v1/me/work-logs', ['work_date' => now()->toDateString(), 'duration_minutes' => 30, 'description' => 'X'])
            ->assertForbidden();
    }

    // --- Field validation ------------------------------------------------------

    public function test_an_unknown_task_public_id_is_rejected(): void
    {
        $this->actingAsLinkedStaff();

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => 'not-a-real-ulid',
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_duration_must_be_a_positive_integer(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 0,
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
    }

    public function test_duration_cannot_exceed_the_maximum(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 1441,
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
    }

    public function test_description_is_required(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
        ])->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_work_date_cannot_be_in_the_future(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->addDay()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_date');
    }

    // --- Historical preservation ---------------------------------------------

    public function test_removing_a_project_membership_does_not_disturb_an_existing_work_log(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $response = $this->postJson('/api/v1/me/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 30,
            'description' => 'Work before leaving.',
        ])->assertCreated();

        $publicId = $response->json('data.public_id');

        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}")->assertNoContent();

        $this->assertDatabaseHas('work_logs', ['public_id' => $publicId, 'staff_id' => $staff->id, 'project_id' => $project->id]);
    }

    public function test_reassigning_a_task_does_not_change_the_work_logs_performer(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'assignee_staff_id' => $staff->id]);

        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id, 'task_id' => $task->id, 'project_id' => $project->id]);

        $replacement = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($replacement, 'staff')->create();
        $task->update(['assignee_staff_id' => $replacement->id]);

        $this->assertSame($staff->id, $workLog->fresh()->staff_id);
    }

    public function test_staff_becoming_inactive_does_not_remove_existing_work_logs(): void
    {
        $staff = Staff::factory()->create();
        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id]);

        $staff->update(['status' => 'inactive']);

        $this->assertDatabaseHas('work_logs', ['id' => $workLog->id]);
    }

    public function test_task_completion_or_cancellation_does_not_remove_existing_work_logs(): void
    {
        $task = Task::factory()->create();
        $workLog = WorkLog::factory()->create(['task_id' => $task->id, 'project_id' => null]);

        $task->update(['status' => 'completed']);
        $this->assertDatabaseHas('work_logs', ['id' => $workLog->id]);

        $task->update(['status' => 'cancelled']);
        $this->assertDatabaseHas('work_logs', ['id' => $workLog->id]);
    }

    public function test_new_work_logs_may_still_be_created_against_a_completed_task(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $task = Task::factory()->completed()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/me/work-logs', [
            'task_id' => $task->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 15,
            'description' => 'Final touch-up logged after completion.',
        ])->assertCreated();
    }

    // --- Self-edit / self-delete ----------------------------------------------

    public function test_a_staff_member_can_edit_their_own_work_log(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id, 'description' => 'Old']);

        $this->putJson("/api/v1/me/work-logs/{$workLog->public_id}", [
            'duration_minutes' => 90,
            'description' => 'Updated description.',
        ])->assertOk()->assertJson(['data' => ['duration_minutes' => 90, 'description' => 'Updated description.']]);
    }

    public function test_a_staff_member_cannot_change_staff_task_or_project_on_self_edit(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id]);
        $otherProject = Project::factory()->create();

        $this->putJson("/api/v1/me/work-logs/{$workLog->public_id}", ['project_id' => $otherProject->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');

        $this->putJson("/api/v1/me/work-logs/{$workLog->public_id}", ['staff_id' => Staff::factory()->create()->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_sending_an_explicit_null_for_an_immutable_field_does_not_clear_it(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'assignee_staff_id' => $staff->id]);
        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id, 'task_id' => $task->id, 'project_id' => $project->id]);

        $this->putJson("/api/v1/me/work-logs/{$workLog->public_id}", ['task_id' => null, 'duration_minutes' => 20])
            ->assertOk();

        $this->assertSame($task->id, $workLog->fresh()->task_id);
        $this->assertSame($project->id, $workLog->fresh()->project_id);
    }

    public function test_a_staff_member_cannot_edit_another_staff_members_work_log(): void
    {
        $this->actingAsLinkedStaff();
        $otherStaff = Staff::factory()->create();
        $workLog = WorkLog::factory()->create(['staff_id' => $otherStaff->id]);

        $this->putJson("/api/v1/me/work-logs/{$workLog->public_id}", ['duration_minutes' => 10])
            ->assertNotFound();
    }

    public function test_a_staff_member_can_delete_their_own_work_log(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/me/work-logs/{$workLog->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('work_logs', ['id' => $workLog->id]);
    }

    public function test_a_staff_member_cannot_delete_another_staff_members_work_log(): void
    {
        $this->actingAsLinkedStaff();
        $otherStaff = Staff::factory()->create();
        $workLog = WorkLog::factory()->create(['staff_id' => $otherStaff->id]);

        $this->deleteJson("/api/v1/me/work-logs/{$workLog->public_id}")->assertNotFound();

        $this->assertDatabaseHas('work_logs', ['id' => $workLog->id]);
    }

    // --- Administrator management -----------------------------------------

    public function test_administrator_can_create_a_work_log_for_another_staff_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $project = Project::factory()->create();

        $this->postJson('/api/v1/work-logs', [
            'staff_id' => $staff->public_id,
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 120,
            'description' => 'Backfilled entry.',
        ])->assertCreated()->assertJson(['data' => ['staff' => ['public_id' => $staff->public_id]]]);
    }

    public function test_administrator_creation_does_not_re_validate_eligibility(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $project = Project::factory()->create();

        $this->postJson('/api/v1/work-logs', [
            'staff_id' => $staff->public_id,
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 60,
            'description' => 'Backfilled for a former project member.',
        ])->assertCreated();
    }

    public function test_administrator_creation_requires_staff_id(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();

        $this->postJson('/api/v1/work-logs', [
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 60,
            'description' => 'Missing staff.',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_administrator_can_correct_any_work_log(): void
    {
        $this->actingAsAdministrator();
        $workLog = WorkLog::factory()->create();

        $this->putJson("/api/v1/work-logs/{$workLog->public_id}", ['duration_minutes' => 200, 'description' => 'Corrected.'])
            ->assertOk()
            ->assertJson(['data' => ['duration_minutes' => 200, 'description' => 'Corrected.']]);
    }

    public function test_administrator_cannot_change_staff_task_or_project_on_update(): void
    {
        $this->actingAsAdministrator();
        $workLog = WorkLog::factory()->create();
        $otherStaff = Staff::factory()->create();

        $this->putJson("/api/v1/work-logs/{$workLog->public_id}", ['staff_id' => $otherStaff->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_administrator_can_delete_any_work_log(): void
    {
        $this->actingAsAdministrator();
        $workLog = WorkLog::factory()->create();

        $this->deleteJson("/api/v1/work-logs/{$workLog->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('work_logs', ['id' => $workLog->id]);
    }

    // --- Deletion protection --------------------------------------------------

    public function test_a_task_with_work_logs_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();
        WorkLog::factory()->create(['task_id' => $task->id, 'project_id' => null]);

        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertStatus(409);
    }

    public function test_a_project_with_directly_linked_work_logs_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        WorkLog::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertStatus(409);
    }

    public function test_a_staff_member_with_work_logs_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        WorkLog::factory()->create(['staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }

    // --- Filters ---------------------------------------------------------------

    public function test_my_work_logs_can_be_filtered_by_project(): void
    {
        $staff = $this->actingAsLinkedStaff();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        WorkLog::factory()->create(['staff_id' => $staff->id, 'project_id' => $project->id, 'description' => 'Belongs']);
        WorkLog::factory()->create(['staff_id' => $staff->id, 'description' => 'Elsewhere']);

        $this->getJson("/api/v1/me/work-logs?project={$project->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'Belongs']]]);
    }

    public function test_my_work_logs_can_be_filtered_by_date_range(): void
    {
        $staff = $this->actingAsLinkedStaff();
        WorkLog::factory()->create(['staff_id' => $staff->id, 'work_date' => '2026-01-01', 'description' => 'January']);
        WorkLog::factory()->create(['staff_id' => $staff->id, 'work_date' => '2026-06-01', 'description' => 'June']);

        $this->getJson('/api/v1/me/work-logs?from=2026-05-01&to=2026-07-01')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'June']]]);
    }

    public function test_privileged_work_logs_can_be_filtered_by_staff(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        WorkLog::factory()->create(['staff_id' => $staff->id, 'description' => 'Mine']);
        WorkLog::factory()->create(['description' => 'Elsewhere']);

        $this->getJson("/api/v1/work-logs?staff={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'Mine']]]);
    }
}
