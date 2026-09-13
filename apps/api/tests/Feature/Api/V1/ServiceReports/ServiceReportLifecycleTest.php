<?php

namespace Tests\Feature\Api\V1\ServiceReports;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workflow transitions (draft -> submitted -> reviewed/rejected,
 * rejected -> draft), editing/deletion immutability boundaries,
 * visibility, and review authority (Phase 18 — Service Reports). See
 * docs/phases/V1_PHASE_18_DEFINITION.md and DEC-041.
 */
class ServiceReportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0: User, 1: Staff}
     */
    private function actingAsStaffMember(): array
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return [$user, $staff];
    }

    /**
     * @return array{0: User, 1: Staff, 2: Staff}
     */
    private function managerWithReport(): array
    {
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $reportStaff = Staff::factory()->create(['manager_id' => $managerStaff->id]);

        return [$managerUser, $managerStaff, $reportStaff];
    }

    // --- Visibility -----------------------------------------------------

    public function test_an_unrelated_staff_member_gets_404_for_a_private_report(): void
    {
        $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertNotFound();
    }

    public function test_the_creator_can_view_their_own_report(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertOk();
    }

    public function test_a_participant_can_view_but_not_edit_or_delete_a_report(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $report->participants()->attach($participant->id);

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertOk();
        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['work_performed' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/service-reports/{$report->public_id}")->assertForbidden();
    }

    public function test_the_creators_current_manager_can_view_the_report(): void
    {
        [$managerUser, , $reportStaff] = $this->managerWithReport();
        $report = ServiceReport::factory()->submitted()->create(['creator_staff_id' => $reportStaff->id]);

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertOk();
    }

    public function test_an_unrelated_manager_does_not_automatically_see_every_service_report(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = ServiceReport::factory()->create();

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertNotFound();
        $this->getJson('/api/v1/service-reports')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_project_lead_can_view_a_report_linked_to_their_project(): void
    {
        $leadUser = User::factory()->staff()->create();
        $leadStaff = Staff::factory()->create(['user_id' => $leadUser->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($leadStaff, 'staff')->projectLead()->create();
        $report = ServiceReport::factory()->submitted()->create(['project_id' => $project->id]);

        Sanctum::actingAs($leadUser);

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertOk();
    }

    public function test_administrator_can_view_any_report(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertOk();
    }

    // --- Editing / immutability ---------------------------------------------

    public function test_the_creator_can_edit_their_own_draft_report(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id, 'work_performed' => 'Old']);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['work_performed' => 'New'])
            ->assertOk()->assertJson(['data' => ['work_performed' => 'New']]);
    }

    public function test_a_submitted_report_cannot_be_edited(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->submitted()->create();

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['work_performed' => 'New'])->assertStatus(409);
    }

    public function test_a_reviewed_report_cannot_be_edited(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->reviewed()->create();

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['work_performed' => 'New'])->assertStatus(409);
    }

    public function test_a_rejected_report_cannot_be_edited_directly(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->rejected()->create();

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['work_performed' => 'New'])->assertStatus(409);
    }

    // --- Draft relationship editability (Client/Project/Task) -------------

    public function test_the_draft_creator_can_change_the_client(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $originalClient = Client::factory()->create();
        $newClient = Client::factory()->create();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id, 'client_id' => $originalClient->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $newClient->public_id])
            ->assertOk()->assertJson(['data' => ['client' => ['public_id' => $newClient->public_id]]]);

        $this->assertDatabaseHas('service_reports', ['id' => $report->id, 'client_id' => $newClient->id]);
    }

    public function test_the_draft_creator_can_change_the_project(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $client = Client::factory()->create();
        $originalProject = Project::factory()->create(['client_id' => $client->id]);
        $newProject = Project::factory()->create(['client_id' => $client->id]);
        $report = ServiceReport::factory()->create([
            'creator_staff_id' => $staff->id, 'client_id' => $client->id, 'project_id' => $originalProject->id,
        ]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['project_id' => $newProject->public_id])
            ->assertOk()->assertJson(['data' => ['project' => ['public_id' => $newProject->public_id]]]);
    }

    public function test_the_draft_creator_can_change_the_task(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $originalTask = Task::factory()->create(['project_id' => $project->id]);
        $newTask = Task::factory()->create(['project_id' => $project->id]);
        $report = ServiceReport::factory()->create([
            'creator_staff_id' => $staff->id, 'client_id' => $client->id, 'project_id' => $project->id, 'task_id' => $originalTask->id,
        ]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['task_id' => $newTask->public_id])
            ->assertOk()->assertJson(['data' => ['task' => ['public_id' => $newTask->public_id]]]);
    }

    public function test_administrator_can_change_client_project_task_on_any_draft(): void
    {
        $this->actingAsAdministrator();
        $originalClient = Client::factory()->create();
        $newClient = Client::factory()->create();
        $report = ServiceReport::factory()->create(['client_id' => $originalClient->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $newClient->public_id])
            ->assertOk()->assertJson(['data' => ['client' => ['public_id' => $newClient->public_id]]]);
    }

    public function test_changing_client_to_one_incompatible_with_the_existing_project_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $report = ServiceReport::factory()->create(['client_id' => $client->id, 'project_id' => $project->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $otherClient->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');

        $this->assertDatabaseHas('service_reports', ['id' => $report->id, 'client_id' => $client->id]);
    }

    public function test_changing_client_together_with_a_coherent_replacement_project_is_accepted(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $replacementProject = Project::factory()->create(['client_id' => $otherClient->id]);
        $report = ServiceReport::factory()->create(['client_id' => $client->id, 'project_id' => $project->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", [
            'client_id' => $otherClient->public_id,
            'project_id' => $replacementProject->public_id,
        ])->assertOk();
    }

    public function test_changing_the_task_to_one_whose_project_belongs_to_a_different_client_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $incompatibleProject = Project::factory()->create(['client_id' => $otherClient->id]);
        $incompatibleTask = Task::factory()->create(['project_id' => $incompatibleProject->id]);
        $report = ServiceReport::factory()->create(['client_id' => $client->id, 'project_id' => null, 'task_id' => null]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['task_id' => $incompatibleTask->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_changing_the_task_while_explicitly_keeping_the_old_project_is_rejected_when_incoherent(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $otherProject = Project::factory()->create(['client_id' => $client->id]);
        $originalTask = Task::factory()->create(['project_id' => $project->id]);
        $newTask = Task::factory()->create(['project_id' => $otherProject->id]);
        $report = ServiceReport::factory()->create([
            'client_id' => $client->id, 'project_id' => $project->id, 'task_id' => $originalTask->id,
        ]);

        // project_id is explicitly re-supplied (unchanged) alongside a
        // task_id whose own project is a different one — a real
        // contradiction, not an omission the single-source-of-truth
        // derivation should silently resolve.
        $this->putJson("/api/v1/service-reports/{$report->public_id}", [
            'project_id' => $project->public_id,
            'task_id' => $newTask->public_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_changing_the_task_alone_re_derives_the_project_as_the_single_source_of_truth(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $originalProject = Project::factory()->create(['client_id' => $client->id]);
        $newProject = Project::factory()->create(['client_id' => $client->id]);
        $originalTask = Task::factory()->create(['project_id' => $originalProject->id]);
        $newTask = Task::factory()->create(['project_id' => $newProject->id]);
        $report = ServiceReport::factory()->create([
            'client_id' => $client->id, 'project_id' => $originalProject->id, 'task_id' => $originalTask->id,
        ]);

        // project_id is deliberately omitted — the new task's own project
        // must be derived automatically, exactly as at creation.
        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['task_id' => $newTask->public_id])
            ->assertOk()->assertJson(['data' => ['project' => ['public_id' => $newProject->public_id]]]);

        $this->assertDatabaseHas('service_reports', ['id' => $report->id, 'project_id' => $newProject->id, 'task_id' => $newTask->id]);
    }

    public function test_a_submitted_report_cannot_change_client_project_or_task(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $newClient = Client::factory()->create();
        $report = ServiceReport::factory()->submitted()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $newClient->public_id])->assertStatus(409);
    }

    public function test_a_rejected_report_cannot_change_client_project_or_task_before_returning_to_draft(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $newClient = Client::factory()->create();
        $report = ServiceReport::factory()->rejected()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $newClient->public_id])->assertStatus(409);
    }

    public function test_a_reviewed_report_cannot_change_client_project_or_task(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $newClient = Client::factory()->create();
        $report = ServiceReport::factory()->reviewed()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $newClient->public_id])->assertStatus(409);
    }

    public function test_a_returned_to_draft_report_can_have_client_project_task_corrected_again(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $client = Client::factory()->create();
        $newClient = Client::factory()->create();
        $report = ServiceReport::factory()->rejected()->create(['creator_staff_id' => $staff->id, 'client_id' => $client->id]);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/return-to-draft")->assertOk();

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['client_id' => $newClient->public_id])
            ->assertOk()->assertJson(['data' => ['client' => ['public_id' => $newClient->public_id]]]);
    }

    public function test_creator_staff_id_cannot_be_changed_via_update(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $otherStaff = Staff::factory()->create();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", [
            'creator_staff_id' => $otherStaff->public_id,
            'work_performed' => 'Updated narrative.',
        ])->assertOk();

        $this->assertDatabaseHas('service_reports', ['id' => $report->id, 'creator_staff_id' => $staff->id]);
    }

    // --- Deletion ---------------------------------------------------------

    public function test_the_creator_can_delete_their_own_draft_report(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('service_reports', ['id' => $report->id]);
    }

    public function test_a_submitted_report_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->submitted()->create();

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}")->assertStatus(409);
    }

    public function test_a_reviewed_report_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->reviewed()->create();

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}")->assertStatus(409);
    }

    public function test_a_rejected_report_cannot_be_directly_deleted(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->rejected()->create();

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}")->assertStatus(409);
    }

    // --- Submit -------------------------------------------------------------

    public function test_the_creator_can_submit_their_own_draft_report(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")
            ->assertOk()->assertJson(['data' => ['status' => 'submitted']]);

        $this->assertDatabaseHas('service_report_actions', ['service_report_id' => $report->id, 'action' => 'submitted']);
    }

    public function test_a_participant_cannot_submit_a_report_they_do_not_own(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $report->participants()->attach($participant->id);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")->assertForbidden();
    }

    public function test_submitting_a_non_draft_report_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->submitted()->create();

        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")->assertStatus(409);
    }

    // --- Review / reject ------------------------------------------------

    public function test_the_creators_manager_can_review_a_submitted_report(): void
    {
        [$managerUser, , $reportStaff] = $this->managerWithReport();
        $report = ServiceReport::factory()->submitted()->create(['creator_staff_id' => $reportStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/review", ['note' => 'Looks good.'])
            ->assertOk()->assertJson(['data' => ['status' => 'reviewed']]);

        $this->assertDatabaseHas('service_report_actions', [
            'service_report_id' => $report->id, 'action' => 'reviewed', 'note' => 'Looks good.',
        ]);
    }

    public function test_a_project_lead_can_review_a_report_linked_to_their_project(): void
    {
        $leadUser = User::factory()->staff()->create();
        $leadStaff = Staff::factory()->create(['user_id' => $leadUser->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($leadStaff, 'staff')->projectLead()->create();
        $report = ServiceReport::factory()->submitted()->create(['project_id' => $project->id]);

        Sanctum::actingAs($leadUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertOk();
    }

    public function test_the_creator_cannot_review_their_own_report(): void
    {
        [$creatorUser, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->submitted()->create(['creator_staff_id' => $staff->id]);

        Sanctum::actingAs($creatorUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertForbidden();
    }

    public function test_an_unrelated_manager_cannot_review_a_report(): void
    {
        // Existence is itself sensitive — a manager with no relationship
        // to this report (not its creator's manager, not a Project Lead,
        // not a participant) cannot view it at all, so this is 404, not
        // 403, mirroring authorizeManageDraft()'s identical shape.
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = ServiceReport::factory()->submitted()->create();

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertNotFound();
    }

    public function test_a_participant_cannot_review_a_report(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->submitted()->create();
        $report->participants()->attach($participant->id);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertForbidden();
    }

    public function test_reviewing_a_draft_report_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertStatus(409);
    }

    public function test_reviewing_an_already_reviewed_report_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->reviewed()->create();

        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertStatus(409);
    }

    public function test_a_manager_can_reject_a_submitted_report_with_a_reason(): void
    {
        [$managerUser, , $reportStaff] = $this->managerWithReport();
        $report = ServiceReport::factory()->submitted()->create(['creator_staff_id' => $reportStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/reject", ['reason' => 'Missing findings detail.'])
            ->assertOk()->assertJson(['data' => ['status' => 'rejected']]);

        $this->assertDatabaseHas('service_report_actions', [
            'service_report_id' => $report->id, 'action' => 'rejected', 'note' => 'Missing findings detail.',
        ]);
    }

    public function test_rejecting_without_a_reason_is_rejected(): void
    {
        [$managerUser, , $reportStaff] = $this->managerWithReport();
        $report = ServiceReport::factory()->submitted()->create(['creator_staff_id' => $reportStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/reject", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    // --- Return to draft / resubmission --------------------------------

    public function test_the_creator_can_return_a_rejected_report_to_draft_and_edit_and_resubmit_it(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->rejected()->create(['creator_staff_id' => $staff->id]);
        // A first submission must already exist in history for the
        // resubmission label to apply.
        $report->actions()->create(['action' => 'submitted']);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/return-to-draft")
            ->assertOk()->assertJson(['data' => ['status' => 'draft']]);

        $this->assertDatabaseHas('service_report_actions', ['service_report_id' => $report->id, 'action' => 'returned_to_draft']);

        $this->putJson("/api/v1/service-reports/{$report->public_id}", ['work_performed' => 'Corrected write-up.'])
            ->assertOk();

        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")
            ->assertOk()->assertJson(['data' => ['status' => 'submitted']]);

        $this->assertDatabaseHas('service_report_actions', ['service_report_id' => $report->id, 'action' => 'resubmitted']);
    }

    public function test_returning_a_non_rejected_report_to_draft_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $this->postJson("/api/v1/service-reports/{$report->public_id}/return-to-draft")->assertStatus(409);
    }

    public function test_only_the_creator_or_administrator_may_return_a_report_to_draft(): void
    {
        [$managerUser, , $reportStaff] = $this->managerWithReport();
        $report = ServiceReport::factory()->rejected()->create(['creator_staff_id' => $reportStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/return-to-draft")->assertForbidden();
    }

    // --- Action history -----------------------------------------------------

    public function test_the_full_workflow_history_is_recorded_in_order(): void
    {
        [$creatorUser, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")->assertOk();

        $this->actingAsAdministrator();
        $this->postJson("/api/v1/service-reports/{$report->public_id}/reject", ['reason' => 'Needs more detail.'])->assertOk();

        // Re-authenticate as the original creator to reopen/resubmit.
        Sanctum::actingAs($creatorUser);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/return-to-draft")->assertOk();
        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")->assertOk();

        $this->actingAsAdministrator();
        $this->postJson("/api/v1/service-reports/{$report->public_id}/review")->assertOk();

        $history = $this->getJson("/api/v1/service-reports/{$report->public_id}")->assertOk()->json('data.history');

        $this->assertSame(
            ['submitted', 'rejected', 'returned_to_draft', 'resubmitted', 'reviewed'],
            array_column($history, 'action')
        );
    }

    // --- Account state ---------------------------------------------------

    public function test_a_suspended_account_cannot_access_service_reports(): void
    {
        $user = User::factory()->staff()->suspended()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/service-reports')->assertForbidden();
    }
}
