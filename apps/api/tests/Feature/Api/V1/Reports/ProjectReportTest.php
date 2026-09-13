<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Projects report (Phase 20 — Reports, DEC-043). No `can:` route
 * middleware — visibility mirrors ProjectController's own
 * `GET /api/v1/projects` (App\Services\Reporting\ProjectVisibility).
 */
class ProjectReportTest extends TestCase
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
        $this->getJson('/api/v1/reports/projects')->assertUnauthorized();
    }

    public function test_a_manager_sees_every_project(): void
    {
        $managerUser = User::factory()->manager()->create();
        Sanctum::actingAs($managerUser);
        Project::factory()->count(3)->create();

        $this->getJson('/api/v1/reports/projects')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_ordinary_staff_member_sees_only_projects_they_belong_to(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mine = Project::factory()->create();
        ProjectMembership::factory()->for($mine, 'project')->for($staff, 'staff')->create();
        Project::factory()->create(); // not a member

        $this->getJson('/api/v1/reports/projects')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $mine->public_id]]]);
    }

    public function test_filters_by_client_and_status(): void
    {
        $this->actingAsAdministrator();

        $client = Client::factory()->create();
        $byClient = Project::factory()->create(['client_id' => $client->id]);
        Project::factory()->create();
        $active = Project::factory()->active()->create();

        $this->getJson("/api/v1/reports/projects?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byClient->public_id]]]);

        $this->getJson('/api/v1/reports/projects?status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $active->public_id]]]);
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        Project::factory()->create();

        $this->getJson('/api/v1/reports/projects')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_matches_json_filters_and_authorization_with_no_internal_ids(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mine = Project::factory()->create(['name' => 'Alpha']);
        ProjectMembership::factory()->for($mine, 'project')->for($staff, 'staff')->create();
        Project::factory()->create(['name' => 'Beta']); // not visible

        $csv = $this->get('/api/v1/reports/projects/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Alpha', $csv);
        $this->assertStringNotContainsString('Beta', $csv);
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        Project::factory()->create(['project_code' => '-2+3']);

        $csv = $this->get('/api/v1/reports/projects/export')->streamedContent();

        $this->assertStringContainsString("'-2+3", $csv);
    }
}
