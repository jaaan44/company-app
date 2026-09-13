<?php

namespace Tests\Feature\Api\V1\IncidentReports;

use App\Enums\IncidentReportStatus;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Visibility, assignment (assign/reassign/start-investigation),
 * editing-authority boundaries per status, the investigation-oriented
 * workflow (reported -> under_investigation -> resolved -> closed, plus
 * reopen), resolution requirements, deletion rules, and action-history
 * recording (Phase 19 — Incident Reports). See docs/phases/
 * V1_PHASE_19_DEFINITION.md and DEC-042.
 */
class IncidentReportLifecycleTest extends TestCase
{
    use RefreshDatabase;

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
        $reporterStaff = Staff::factory()->create(['manager_id' => $managerStaff->id]);

        return [$managerUser, $managerStaff, $reporterStaff];
    }

    // --- Visibility -----------------------------------------------------

    public function test_an_unrelated_staff_member_gets_404_for_a_private_incident(): void
    {
        $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertNotFound();
    }

    public function test_the_reporter_can_view_their_own_incident(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertOk();
    }

    public function test_the_assigned_investigator_can_view_the_incident(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertOk();
    }

    public function test_a_participant_can_view_but_not_edit_or_delete_an_incident(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $report->participants()->attach($participant->id);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertOk();
        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertForbidden();
    }

    public function test_the_reporters_current_manager_can_view_the_incident(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertOk();
    }

    public function test_an_unrelated_manager_does_not_automatically_see_every_incident(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = IncidentReport::factory()->create();

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertNotFound();
        $this->getJson('/api/v1/incident-reports')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_project_lead_does_not_automatically_see_an_incident_linked_to_their_project(): void
    {
        $leadUser = User::factory()->staff()->create();
        $leadStaff = Staff::factory()->create(['user_id' => $leadUser->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($leadStaff, 'staff')->projectLead()->create();
        $report = IncidentReport::factory()->create(['project_id' => $project->id]);

        Sanctum::actingAs($leadUser);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertNotFound();
    }

    public function test_administrator_can_view_any_incident(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertOk();
    }

    // --- Editing authority: reported ---------------------------------------

    public function test_the_reporter_can_edit_their_own_reported_incident(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id, 'description' => 'Old']);

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'New'])
            ->assertOk()->assertJson(['data' => ['description' => 'New']]);
    }

    public function test_the_reporters_manager_can_edit_a_reported_incident(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Updated'])->assertOk();
    }

    public function test_the_assigned_investigator_can_edit_a_reported_incident(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Updated'])->assertOk();
    }

    public function test_an_unrelated_staff_participant_cannot_edit_a_reported_incident(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $report->participants()->attach($participant->id);

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Updated'])->assertForbidden();
    }

    // --- Editing authority: under_investigation -----------------------------

    public function test_the_reporter_cannot_edit_once_under_investigation_unless_also_investigator_or_manager(): void
    {
        [$reporterUser, $reporterStaff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->underInvestigation()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($reporterUser);
        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Updated'])->assertForbidden();
    }

    public function test_the_assigned_investigator_can_edit_while_under_investigation(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create([
            'assigned_to_staff_id' => $investigator->id,
            'status' => IncidentReportStatus::UnderInvestigation,
        ]);

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Updated'])->assertOk();
    }

    public function test_the_reporters_manager_can_edit_while_under_investigation(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $report = IncidentReport::factory()->create([
            'reporter_staff_id' => $reporterStaff->id,
            'assigned_to_staff_id' => Staff::factory()->create()->id,
            'status' => IncidentReportStatus::UnderInvestigation,
        ]);

        Sanctum::actingAs($managerUser);
        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Updated'])->assertOk();
    }

    // --- Immutability: resolved / closed ------------------------------------

    public function test_a_resolved_incident_cannot_be_edited(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->resolved()->create();

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'New'])->assertStatus(409);
    }

    public function test_a_closed_incident_cannot_be_edited(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->closed()->create();

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'New'])->assertStatus(409);
    }

    public function test_reporter_staff_id_cannot_be_changed_via_update(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $otherStaff = Staff::factory()->create();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", [
            'reporter_staff_id' => $otherStaff->public_id,
            'description' => 'Updated narrative.',
        ])->assertOk();

        $this->assertDatabaseHas('incident_reports', ['id' => $report->id, 'reporter_staff_id' => $staff->id]);
    }

    public function test_assigned_to_staff_id_cannot_be_changed_via_generic_update(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $investigator = Staff::factory()->create();
        $otherInvestigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id, 'assigned_to_staff_id' => $investigator->id]);

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", [
            'assigned_to_staff_id' => $otherInvestigator->public_id,
            'description' => 'Updated.',
        ])->assertOk();

        $this->assertDatabaseHas('incident_reports', ['id' => $report->id, 'assigned_to_staff_id' => $investigator->id]);
    }

    // --- Deletion ---------------------------------------------------------

    public function test_the_reporter_can_delete_their_own_unassigned_reported_incident(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('incident_reports', ['id' => $report->id]);
    }

    public function test_administrator_can_delete_an_unassigned_reported_incident(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertNoContent();
    }

    public function test_deletion_is_blocked_once_an_incident_is_assigned(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->assigned()->create();

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertStatus(409);
        $this->assertDatabaseHas('incident_reports', ['id' => $report->id]);
    }

    public function test_deletion_is_blocked_once_under_investigation(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->underInvestigation()->create();

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertStatus(409);
    }

    public function test_deletion_is_blocked_once_resolved_or_closed(): void
    {
        $this->actingAsAdministrator();
        $resolved = IncidentReport::factory()->resolved()->create();
        $closed = IncidentReport::factory()->closed()->create();

        $this->deleteJson("/api/v1/incident-reports/{$resolved->public_id}")->assertStatus(409);
        $this->deleteJson("/api/v1/incident-reports/{$closed->public_id}")->assertStatus(409);
    }

    public function test_a_reporters_manager_cannot_delete_the_incident(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertForbidden();
    }

    // --- Assignment ---------------------------------------------------------

    public function test_the_reporters_manager_can_assign_an_investigator(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $investigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $investigator->public_id])
            ->assertOk()->assertJson(['data' => ['assigned_to' => ['public_id' => $investigator->public_id]]]);

        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'assigned']);
    }

    public function test_administrator_can_assign_an_investigator(): void
    {
        $this->actingAsAdministrator();
        $investigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $investigator->public_id])
            ->assertOk();
    }

    public function test_the_reporter_cannot_assign_an_investigator(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $investigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $investigator->public_id])
            ->assertForbidden();
    }

    public function test_an_unrelated_staff_member_cannot_assign_an_investigator(): void
    {
        $this->actingAsStaffMember();
        $investigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create();

        // Existence itself is sensitive — an unrelated Staff member
        // cannot view this report at all, so this is 404, not 403.
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $investigator->public_id])
            ->assertNotFound();
    }

    public function test_assigning_an_already_assigned_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->assigned()->create();
        $newInvestigator = Staff::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $newInvestigator->public_id])
            ->assertStatus(409);
    }

    public function test_reassigning_an_unassigned_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();
        $investigator = Staff::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reassign", ['assigned_to_staff_id' => $investigator->public_id])
            ->assertStatus(409);
    }

    public function test_the_reporters_manager_can_reassign_an_already_assigned_incident(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $originalInvestigator = Staff::factory()->create();
        $newInvestigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create([
            'reporter_staff_id' => $reporterStaff->id, 'assigned_to_staff_id' => $originalInvestigator->id,
        ]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reassign", ['assigned_to_staff_id' => $newInvestigator->public_id])
            ->assertOk()->assertJson(['data' => ['assigned_to' => ['public_id' => $newInvestigator->public_id]]]);

        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'reassigned']);
    }

    public function test_the_assigned_investigator_cannot_reassign_themselves_away(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $newInvestigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reassign", ['assigned_to_staff_id' => $newInvestigator->public_id])
            ->assertForbidden();
    }

    public function test_assignment_is_blocked_once_resolved_or_closed(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->resolved()->create(['assigned_to_staff_id' => null]);
        $investigator = Staff::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $investigator->public_id])
            ->assertStatus(409);
    }

    // --- Start investigation --------------------------------------------

    public function test_starting_investigation_requires_an_assignee(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/start-investigation", [])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to_staff_id');
    }

    public function test_starting_investigation_with_an_existing_assignee_succeeds(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/start-investigation", [])
            ->assertOk()->assertJson(['data' => ['status' => 'under_investigation']]);

        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'investigation_started']);
    }

    public function test_starting_investigation_can_bundle_the_initial_assignment(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $investigator = Staff::factory()->create();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/start-investigation", [
            'assigned_to_staff_id' => $investigator->public_id,
        ])->assertOk()->assertJson(['data' => ['status' => 'under_investigation', 'assigned_to' => ['public_id' => $investigator->public_id]]]);

        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'assigned']);
        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'investigation_started']);
    }

    public function test_an_unrelated_staff_member_cannot_bundle_an_assignment_into_start_investigation(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['assigned_to_staff_id' => $staff->id]);
        $anotherStaff = Staff::factory()->create();

        // The assigned investigator has investigation-management
        // authority but NOT assignment authority — attempting to also
        // reassign in the same call is rejected.
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/start-investigation", [
            'assigned_to_staff_id' => $anotherStaff->public_id,
        ])->assertForbidden();
    }

    public function test_starting_investigation_on_a_non_reported_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->underInvestigation()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/start-investigation", [])->assertStatus(409);
    }

    // --- Resolve ----------------------------------------------------------

    public function test_resolving_requires_a_resolution(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create([
            'assigned_to_staff_id' => $investigator->id,
            'status' => IncidentReportStatus::UnderInvestigation,
        ]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", ['corrective_action' => 'Fixed it.'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');
    }

    public function test_resolving_requires_corrective_action_or_immediate_action_taken(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create([
            'assigned_to_staff_id' => $investigator->id,
            'status' => IncidentReportStatus::UnderInvestigation,
            'corrective_action' => null,
            'immediate_action_taken' => null,
        ]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", ['resolution' => 'All fixed.'])
            ->assertUnprocessable()->assertJsonValidationErrors('corrective_action');
    }

    public function test_resolving_does_not_require_root_cause(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create([
            'assigned_to_staff_id' => $investigator->id,
            'status' => IncidentReportStatus::UnderInvestigation,
        ]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [
            'resolution' => 'All fixed.',
            'immediate_action_taken' => 'Cordoned off the area.',
        ])->assertOk()->assertJson(['data' => ['status' => 'resolved']]);
    }

    public function test_resolving_accepts_a_previously_set_resolution_without_resupplying_it(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create([
            'assigned_to_staff_id' => $investigator->id,
            'status' => IncidentReportStatus::UnderInvestigation,
            'resolution' => 'Already documented.',
            'corrective_action' => 'Already fixed.',
        ]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [])
            ->assertOk()->assertJson(['data' => ['status' => 'resolved']]);

        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'resolved']);
    }

    public function test_resolving_a_reported_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [
            'resolution' => 'X', 'corrective_action' => 'Y',
        ])->assertStatus(409);
    }

    public function test_the_reporter_cannot_resolve_the_incident(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->underInvestigation()->create(['reporter_staff_id' => $staff->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [
            'resolution' => 'X', 'corrective_action' => 'Y',
        ])->assertForbidden();
    }

    // --- Close --------------------------------------------------------------

    public function test_the_assigned_investigator_can_close_a_resolved_incident(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->resolved()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/close")
            ->assertOk()->assertJson(['data' => ['status' => 'closed']]);

        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'closed']);
    }

    public function test_a_project_lead_does_not_automatically_gain_close_authority(): void
    {
        $leadUser = User::factory()->staff()->create();
        $leadStaff = Staff::factory()->create(['user_id' => $leadUser->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($leadStaff, 'staff')->projectLead()->create();
        $report = IncidentReport::factory()->resolved()->create(['project_id' => $project->id]);

        Sanctum::actingAs($leadUser);
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/close")->assertNotFound();
    }

    public function test_closing_a_report_still_under_investigation_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->underInvestigation()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/close")->assertStatus(409);
    }

    // --- Reopen -------------------------------------------------------------

    public function test_reopening_a_resolved_incident_returns_it_to_under_investigation(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->resolved()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reopen", ['note' => 'New evidence found.'])
            ->assertOk()->assertJson(['data' => ['status' => 'under_investigation']]);

        $this->assertDatabaseHas('incident_report_actions', [
            'incident_report_id' => $report->id, 'action' => 'reopened', 'note' => 'New evidence found.',
        ]);
        $this->assertDatabaseMissing('incident_reports', ['id' => $report->id, 'status' => 'reopened']);
    }

    public function test_reopening_a_closed_incident_returns_it_to_under_investigation(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->closed()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reopen")
            ->assertOk()->assertJson(['data' => ['status' => 'under_investigation']]);
    }

    public function test_reopening_restores_editing_capability(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->resolved()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reopen")->assertOk();

        $this->putJson("/api/v1/incident-reports/{$report->public_id}", ['description' => 'Corrected after reopening.'])
            ->assertOk()->assertJson(['data' => ['description' => 'Corrected after reopening.']]);
    }

    public function test_reopening_a_reported_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reopen")->assertStatus(409);
    }

    // --- Invalid transitions -------------------------------------------------

    public function test_reassigning_a_resolved_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->resolved()->create();
        $newInvestigator = Staff::factory()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reassign", ['assigned_to_staff_id' => $newInvestigator->public_id])
            ->assertStatus(409);
    }

    public function test_resolving_an_already_resolved_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->resolved()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [
            'resolution' => 'X', 'corrective_action' => 'Y',
        ])->assertStatus(409);
    }

    public function test_closing_an_already_closed_incident_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->closed()->create();

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/close")->assertStatus(409);
    }

    // --- Full action history -------------------------------------------------

    public function test_the_full_workflow_and_assignment_history_is_recorded_in_order(): void
    {
        [$managerUser, , $reporterStaff] = $this->managerWithReport();
        $investigator = Staff::factory()->create();
        $otherInvestigator = Staff::factory()->create();

        $report = IncidentReport::factory()->create(['reporter_staff_id' => $reporterStaff->id]);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/assign", ['assigned_to_staff_id' => $investigator->public_id])->assertOk();
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reassign", ['assigned_to_staff_id' => $otherInvestigator->public_id])->assertOk();
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/start-investigation")->assertOk();

        $this->actingAsAdministrator();
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [
            'resolution' => 'Resolved.', 'corrective_action' => 'Fixed.',
        ])->assertOk();
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reopen")->assertOk();
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve", [])->assertOk();
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/close")->assertOk();

        $history = $this->getJson("/api/v1/incident-reports/{$report->public_id}")->assertOk()->json('data.history');

        $this->assertSame(
            ['reported', 'assigned', 'reassigned', 'investigation_started', 'resolved', 'reopened', 'resolved', 'closed'],
            array_column($history, 'action')
        );
    }

    // --- Account state ---------------------------------------------------

    public function test_a_suspended_account_cannot_access_incident_reports(): void
    {
        $user = User::factory()->staff()->suspended()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/incident-reports')->assertForbidden();
    }
}
