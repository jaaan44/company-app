<?php

namespace Tests\Feature\Api\V1\IncidentReports;

use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creation, structural validation, optional-anchor combinations,
 * relational coherence, creation authority/eligibility, filters, and API
 * shape (Phase 19 — Incident Reports). See
 * docs/phases/V1_PHASE_19_DEFINITION.md and DEC-042.
 */
class IncidentReportTest extends TestCase
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

    // --- Creation: fully internal / optional anchors -----------------------

    public function test_a_staff_linked_user_can_report_a_fully_internal_incident_with_no_client_project_or_task(): void
    {
        [, $staff] = $this->actingAsStaffMember();

        $response = $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'workplace_safety',
            'description' => 'Slipped on a wet floor near the break room.',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'client' => null,
                    'project' => null,
                    'task' => null,
                    'reporter' => ['public_id' => $staff->public_id],
                    'assigned_to' => null,
                    'status' => 'reported',
                    'severity' => 'medium',
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('incident_reports', [
            'reporter_staff_id' => $staff->id, 'client_id' => null, 'project_id' => null, 'task_id' => null,
        ]);
        $this->assertDatabaseHas('incident_report_actions', ['action' => 'reported']);
    }

    public function test_an_incident_can_be_reported_with_only_a_client(): void
    {
        $this->actingAsStaffMember();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/incident-reports', [
            'client_id' => $client->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'client_site',
            'description' => 'X',
        ])->assertCreated()->assertJson(['data' => ['client' => ['public_id' => $client->public_id], 'project' => null]]);
    }

    public function test_an_incident_can_be_reported_with_a_project_and_no_client(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $project = Project::factory()->create(['client_id' => null]);
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/incident-reports', [
            'project_id' => $project->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'operational',
            'description' => 'X',
        ])->assertCreated()->assertJson(['data' => ['project' => ['public_id' => $project->public_id], 'client' => null]]);
    }

    public function test_a_user_with_no_linked_staff_record_cannot_report_an_incident(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertForbidden();
    }

    public function test_an_inactive_staff_member_cannot_self_report_an_incident(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->inactive()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertForbidden();
    }

    public function test_creating_an_incident_requires_occurred_at_incident_type_and_description(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/incident-reports', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['occurred_at', 'incident_type', 'description']);
    }

    public function test_a_future_occurred_at_is_rejected(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => now()->addDay()->toIso8601String(),
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
    }

    public function test_an_invalid_incident_type_is_rejected(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'not-a-real-type',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('incident_type');
    }

    public function test_an_invalid_severity_is_rejected(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'severity' => 'catastrophic',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('severity');
    }

    public function test_severity_defaults_to_medium_when_omitted(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertCreated()->assertJson(['data' => ['severity' => 'medium']]);
    }

    public function test_a_supplied_severity_is_honored(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'it_security',
            'severity' => 'critical',
            'description' => 'X',
        ])->assertCreated()->assertJson(['data' => ['severity' => 'critical']]);
    }

    public function test_assigned_to_staff_id_cannot_be_supplied_at_creation(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $otherStaff = Staff::factory()->create();

        $response = $this->postJson('/api/v1/incident-reports', [
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
            'assigned_to_staff_id' => $otherStaff->public_id,
        ]);

        $response->assertCreated()->assertJson(['data' => ['assigned_to' => null]]);
        $this->assertDatabaseHas('incident_reports', ['reporter_staff_id' => $staff->id, 'assigned_to_staff_id' => null]);
    }

    public function test_administrator_can_report_an_incident_on_behalf_of_another_staff_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->inactive()->create();

        $this->postJson('/api/v1/incident-reports', [
            'reporter_staff_id' => $staff->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'Backfilled historical record.',
        ])->assertCreated()->assertJson(['data' => ['reporter' => ['public_id' => $staff->public_id]]]);
    }

    public function test_a_non_administrator_cannot_report_an_incident_for_someone_else(): void
    {
        $this->actingAsStaffMember();
        $otherStaff = Staff::factory()->create();

        $this->postJson('/api/v1/incident-reports', [
            'reporter_staff_id' => $otherStaff->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertForbidden();
    }

    // --- Relational coherence ------------------------------------------------

    public function test_a_project_belonging_to_a_different_client_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $otherClient->id]);

        $this->postJson('/api/v1/incident-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'reporter_staff_id' => Staff::factory()->create()->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_a_task_belonging_to_a_different_project_than_supplied_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $otherProject = Project::factory()->create(['client_id' => $client->id]);
        $task = Task::factory()->create(['project_id' => $otherProject->id]);

        $this->postJson('/api/v1/incident-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'task_id' => $task->public_id,
            'reporter_staff_id' => Staff::factory()->create()->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_project_id_is_derived_from_task_when_omitted_and_must_belong_to_the_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $wrongClient = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $wrongClient->id]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/incident-reports', [
            'client_id' => $client->public_id,
            'task_id' => $task->public_id,
            'reporter_staff_id' => Staff::factory()->create()->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_an_independent_task_combined_with_a_project_id_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $independentTask = Task::factory()->create(['project_id' => null]);

        $this->postJson('/api/v1/incident-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'task_id' => $independentTask->public_id,
            'reporter_staff_id' => Staff::factory()->create()->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_a_task_alone_with_no_client_or_project_is_accepted_and_project_is_derived(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/incident-reports', [
            'task_id' => $task->public_id,
            'reporter_staff_id' => Staff::factory()->create()->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ])->assertCreated()->assertJson(['data' => ['project' => ['public_id' => $project->public_id]]]);
    }

    public function test_a_coherent_client_project_and_task_are_accepted(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $response = $this->postJson('/api/v1/incident-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'task_id' => $task->public_id,
            'reporter_staff_id' => Staff::factory()->create()->public_id,
            'occurred_at' => '2026-09-10T08:30:00Z',
            'incident_type' => 'other',
            'description' => 'X',
        ]);

        $response->assertCreated()->assertJson([
            'data' => ['project' => ['public_id' => $project->public_id], 'task' => ['public_id' => $task->public_id]],
        ]);
    }

    // --- API shape / filters -----------------------------------------------

    public function test_viewing_an_incident_report_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $this->getJson("/api/v1/incident-reports/{$report->id}")->assertNotFound();
    }

    public function test_reports_can_be_filtered_by_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        IncidentReport::factory()->create(['client_id' => $client->id, 'description' => 'Matching']);
        IncidentReport::factory()->create(['description' => 'Other']);

        $this->getJson("/api/v1/incident-reports?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'Matching']]]);
    }

    public function test_reports_can_be_filtered_by_project(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        IncidentReport::factory()->create(['project_id' => $project->id, 'description' => 'Matching']);
        IncidentReport::factory()->create(['description' => 'Other']);

        $this->getJson("/api/v1/incident-reports?project={$project->public_id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reports_can_be_filtered_by_task(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();
        IncidentReport::factory()->create(['task_id' => $task->id, 'description' => 'Matching']);
        IncidentReport::factory()->create(['description' => 'Other']);

        $this->getJson("/api/v1/incident-reports?task={$task->public_id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reports_can_be_filtered_by_reporter(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        IncidentReport::factory()->create(['reporter_staff_id' => $staff->id, 'description' => 'Matching']);
        IncidentReport::factory()->create(['description' => 'Other']);

        $this->getJson("/api/v1/incident-reports?reporter={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reports_can_be_filtered_by_assigned_staff(): void
    {
        $this->actingAsAdministrator();
        $investigator = Staff::factory()->create();
        IncidentReport::factory()->create(['assigned_to_staff_id' => $investigator->id, 'description' => 'Matching']);
        IncidentReport::factory()->create(['description' => 'Other']);

        $this->getJson("/api/v1/incident-reports?assigned={$investigator->public_id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reports_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->underInvestigation()->create(['description' => 'Under investigation one']);
        IncidentReport::factory()->create(['description' => 'Reported one']);

        $this->getJson('/api/v1/incident-reports?status=under_investigation')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'Under investigation one']]]);
    }

    public function test_reports_can_be_filtered_by_severity(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->create(['severity' => 'critical', 'description' => 'Critical one']);
        IncidentReport::factory()->create(['severity' => 'low', 'description' => 'Low one']);

        $this->getJson('/api/v1/incident-reports?severity=critical')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'Critical one']]]);
    }

    public function test_reports_can_be_filtered_by_incident_type(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->create(['incident_type' => 'it_security', 'description' => 'IT one']);
        IncidentReport::factory()->create(['incident_type' => 'other', 'description' => 'Other one']);

        $this->getJson('/api/v1/incident-reports?incident_type=it_security')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'IT one']]]);
    }

    public function test_reports_can_be_filtered_by_occurrence_date_range(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->create(['occurred_at' => '2026-01-05T10:00:00Z', 'description' => 'In range']);
        IncidentReport::factory()->create(['occurred_at' => '2026-03-01T10:00:00Z', 'description' => 'Out of range']);

        $this->getJson('/api/v1/incident-reports?from=2026-01-01&to=2026-01-31')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['description' => 'In range']]]);
    }

    public function test_reports_can_be_paginated(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/incident-reports?per_page=2')->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertArrayHasKey('links', $response->json());
        $this->assertArrayHasKey('meta', $response->json());
    }

    public function test_occurred_at_is_returned_in_iso8601_utc(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create(['occurred_at' => '2026-09-10T08:30:00+02:00']);

        $this->getJson("/api/v1/incident-reports/{$report->public_id}")
            ->assertOk()->assertJson(['data' => ['occurred_at' => '2026-09-10T06:30:00+00:00']]);
    }
}
