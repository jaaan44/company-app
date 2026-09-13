<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Service Reports report (Phase 20 — Reports, DEC-043). No `can:`
 * route middleware — visibility mirrors ServiceReportController's own
 * `GET /api/v1/service-reports` exactly
 * (App\Services\Reporting\ServiceReportVisibility): creator,
 * participants, creator's current Manager, linked Project's Project
 * Lead, or Administrator. Phase 18's visibility is never widened here.
 */
class ServiceReportReportTest extends TestCase
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
        $this->getJson('/api/v1/reports/service-reports')->assertUnauthorized();
    }

    public function test_administrator_sees_every_service_report(): void
    {
        $this->actingAsAdministrator();
        ServiceReport::factory()->count(2)->create();

        $this->getJson('/api/v1/reports/service-reports')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_stranger_sees_no_service_reports_and_cannot_infer_their_existence_via_count(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        ServiceReport::factory()->count(5)->create();

        $this->getJson('/api/v1/reports/service-reports')->assertOk()->assertJsonCount(0, 'data');

        $csv = $this->get('/api/v1/reports/service-reports/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Public ID', $csv);
        $this->assertSame(1, substr_count($csv, "\n")); // header only
    }

    public function test_a_project_lead_sees_service_reports_linked_to_their_project(): void
    {
        $user = User::factory()->staff()->create();
        $lead = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($lead, 'staff')->create();

        $visible = ServiceReport::factory()->create(['project_id' => $project->id]);
        ServiceReport::factory()->create(); // unrelated

        $this->getJson('/api/v1/reports/service-reports')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $visible->public_id]]]);
    }

    public function test_a_managers_direct_reports_service_report_is_visible_to_that_manager(): void
    {
        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        Sanctum::actingAs($managerUser);

        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        $visible = ServiceReport::factory()->create(['creator_staff_id' => $report->id]);
        ServiceReport::factory()->create(); // unrelated

        $this->getJson('/api/v1/reports/service-reports')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $visible->public_id]]]);
    }

    public function test_filters_by_client_project_task_staff_status_and_date_range(): void
    {
        $this->actingAsAdministrator();

        $client = Client::factory()->create();
        $byClient = ServiceReport::factory()->create(['client_id' => $client->id, 'service_date' => '2026-05-01']);
        $submitted = ServiceReport::factory()->submitted()->create(['service_date' => '2026-05-01']);
        ServiceReport::factory()->create(['service_date' => '2020-01-01']); // outside range

        $this->getJson("/api/v1/reports/service-reports?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byClient->public_id]]]);

        $this->getJson('/api/v1/reports/service-reports?status=submitted')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $submitted->public_id]]]);

        $this->getJson('/api/v1/reports/service-reports?from=2026-01-01&to=2026-12-31')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        ServiceReport::factory()->create();

        $this->getJson('/api/v1/reports/service-reports')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        ServiceReport::factory()->create(['work_performed' => '=cmd']);

        $csv = $this->get('/api/v1/reports/service-reports/export')->streamedContent();

        $this->assertStringContainsString("'=cmd", $csv);
    }
}
