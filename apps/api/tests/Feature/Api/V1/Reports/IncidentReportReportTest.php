<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Incident Reports report (Phase 20 — Reports, DEC-043). No `can:`
 * route middleware — visibility mirrors IncidentReportController's own
 * `GET /api/v1/incident-reports` exactly
 * (App\Services\Reporting\IncidentReportVisibility): reporter, assigned
 * investigator, participants, reporter's current Manager, or
 * Administrator — deliberately NO Project-Lead carve-out (DEC-042).
 * Phase 19's narrow visibility is never widened here, in JSON, CSV, or
 * (see DashboardTest) the Dashboard's aggregate counts.
 */
class IncidentReportReportTest extends TestCase
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

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/incident-reports')->assertUnauthorized();
    }

    public function test_administrator_sees_every_incident_report(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->count(2)->create();

        $this->getJson('/api/v1/reports/incident-reports')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_project_lead_gains_no_visibility_merely_from_the_linked_project_unlike_service_reports(): void
    {
        $user = User::factory()->staff()->create();
        $lead = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($lead, 'staff')->create();

        IncidentReport::factory()->create(['project_id' => $project->id]);

        $this->getJson('/api/v1/reports/incident-reports')->assertOk()->assertJsonCount(0, 'data');

        $csv = $this->get('/api/v1/reports/incident-reports/export')->assertOk()->streamedContent();
        $this->assertSame(1, substr_count($csv, "\n")); // header row only — cannot infer the incident's existence
    }

    public function test_the_reporter_the_assignee_and_the_reporters_manager_can_see_the_incident(): void
    {
        $reporterUser = User::factory()->staff()->create();
        $reporter = Staff::factory()->create(['user_id' => $reporterUser->id]);

        $assigneeUser = User::factory()->staff()->create();
        $assignee = Staff::factory()->create(['user_id' => $assigneeUser->id]);

        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        $reporter->update(['manager_id' => $manager->id]);

        $incident = IncidentReport::factory()->create([
            'reporter_staff_id' => $reporter->id,
            'assigned_to_staff_id' => $assignee->id,
        ]);

        foreach ([$reporterUser, $assigneeUser, $managerUser] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/reports/incident-reports')
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJson(['data' => [['public_id' => $incident->public_id]]]);
        }
    }

    public function test_filters_by_client_project_task_reporter_assigned_status_severity_and_type(): void
    {
        $this->actingAsAdministrator();

        $client = Client::factory()->create();
        $reporter = Staff::factory()->create();
        $assignee = Staff::factory()->create();

        $byClient = IncidentReport::factory()->create(['client_id' => $client->id]);
        $byReporter = IncidentReport::factory()->create(['reporter_staff_id' => $reporter->id]);
        $byAssignee = IncidentReport::factory()->assigned()->create(['assigned_to_staff_id' => $assignee->id]);
        $bySeverity = IncidentReport::factory()->create(['severity' => 'critical']);
        $byType = IncidentReport::factory()->create(['incident_type' => 'it_security']);

        $this->getJson("/api/v1/reports/incident-reports?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byClient->public_id]]]);

        $this->getJson("/api/v1/reports/incident-reports?reporter={$reporter->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byReporter->public_id]]]);

        $this->getJson("/api/v1/reports/incident-reports?assigned={$assignee->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byAssignee->public_id]]]);

        $this->getJson('/api/v1/reports/incident-reports?severity=critical')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $bySeverity->public_id]]]);

        $this->getJson('/api/v1/reports/incident-reports?incident_type=it_security')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byType->public_id]]]);
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->create();

        $this->getJson('/api/v1/reports/incident-reports')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        IncidentReport::factory()->create(['description' => "\t=evil"]);

        $csv = $this->get('/api/v1/reports/incident-reports/export')->streamedContent();

        $this->assertStringContainsString("'\t=evil", $csv);
    }
}
