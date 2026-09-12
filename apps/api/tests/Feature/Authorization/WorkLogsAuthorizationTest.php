<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 12 Work Logs endpoints
 * (CLAUDE.md §18): `work-logs.view` is Manager-only and scoped to direct
 * reports (mirroring Phase 9's `location.view`, not Phase 10/11's
 * company-wide Manager grant); `work-logs.manage` is Administrator-only;
 * a Project Lead has read-only visibility into their led Projects' Work
 * Logs and no other role gets any correction authority. See
 * docs/phases/V1_PHASE_12_DEFINITION.md.
 */
class WorkLogsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_every_work_log(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $workLog = WorkLog::factory()->create();

        $this->getJson('/api/v1/work-logs')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/work-logs/{$workLog->public_id}")->assertOk();
        $this->putJson("/api/v1/work-logs/{$workLog->public_id}", ['duration_minutes' => 15])->assertOk();
        $this->deleteJson("/api/v1/work-logs/{$workLog->public_id}")->assertNoContent();
    }

    public function test_a_manager_sees_only_their_direct_reports_work_logs(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $unrelated = Staff::factory()->create();
        $reportLog = WorkLog::factory()->create(['staff_id' => $report->id]);
        WorkLog::factory()->create(['staff_id' => $unrelated->id]);
        Sanctum::actingAs($managerUser);

        $this->getJson('/api/v1/work-logs')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $reportLog->public_id]]]);

        $this->getJson("/api/v1/work-logs/{$reportLog->public_id}")->assertOk();
    }

    public function test_a_manager_cannot_view_an_unrelated_staff_members_work_log(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $unrelated = Staff::factory()->create();
        $workLog = WorkLog::factory()->create(['staff_id' => $unrelated->id]);
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/work-logs/{$workLog->public_id}")->assertForbidden();
    }

    public function test_a_manager_cannot_edit_or_delete_a_work_log(): void
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $workLog = WorkLog::factory()->create(['staff_id' => $report->id]);
        Sanctum::actingAs($managerUser);

        $this->putJson("/api/v1/work-logs/{$workLog->public_id}", ['duration_minutes' => 10])->assertForbidden();
        $this->deleteJson("/api/v1/work-logs/{$workLog->public_id}")->assertForbidden();
        $this->postJson('/api/v1/work-logs', [
            'staff_id' => $report->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 10,
            'description' => 'X',
        ])->assertForbidden();
    }

    public function test_a_project_lead_can_view_work_logs_within_their_led_project(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($staff, 'staff')->create();
        $projectLog = WorkLog::factory()->create(['project_id' => $project->id]);
        $task = Task::factory()->create(['project_id' => $project->id]);
        $taskLog = WorkLog::factory()->create(['task_id' => $task->id, 'project_id' => $project->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/work-logs')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/work-logs/{$projectLog->public_id}")->assertOk();
        $this->getJson("/api/v1/work-logs/{$taskLog->public_id}")->assertOk();
    }

    public function test_a_project_lead_cannot_view_work_logs_in_a_project_they_do_not_lead(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $ledProject = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($ledProject, 'project')->for($staff, 'staff')->create();
        $otherProject = Project::factory()->create();
        $otherLog = WorkLog::factory()->create(['project_id' => $otherProject->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/work-logs/{$otherLog->public_id}")->assertForbidden();
        $this->getJson('/api/v1/work-logs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_project_lead_cannot_edit_delete_or_create_work_logs_for_others(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($staff, 'staff')->create();
        $workLog = WorkLog::factory()->create(['project_id' => $project->id]);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/work-logs/{$workLog->public_id}", ['duration_minutes' => 10])->assertForbidden();
        $this->deleteJson("/api/v1/work-logs/{$workLog->public_id}")->assertForbidden();
        $this->postJson('/api/v1/work-logs', [
            'staff_id' => $staff->public_id,
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 10,
            'description' => 'X',
        ])->assertForbidden();
    }

    public function test_an_ordinary_project_member_cannot_see_another_members_work_log_via_shared_project_membership(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        $otherMember = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($otherMember, 'staff')->create();
        $otherLog = WorkLog::factory()->create(['staff_id' => $otherMember->id, 'project_id' => $project->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/work-logs/{$otherLog->public_id}")->assertForbidden();
        $this->getJson('/api/v1/work-logs')->assertForbidden();
        $this->getJson('/api/v1/me/work-logs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_user_with_no_relevant_permission_or_lead_role_cannot_access_the_top_level_endpoint(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/work-logs')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $workLog = WorkLog::factory()->create();

        $this->getJson('/api/v1/work-logs')->assertUnauthorized();
        $this->getJson("/api/v1/work-logs/{$workLog->public_id}")->assertUnauthorized();
        $this->getJson('/api/v1/me/work-logs')->assertUnauthorized();
        $this->postJson('/api/v1/me/work-logs', ['work_date' => now()->toDateString(), 'duration_minutes' => 10, 'description' => 'X'])
            ->assertUnauthorized();
    }

    public function test_suspended_account_loses_work_logs_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/work-logs')
            ->assertForbidden();
    }
}
