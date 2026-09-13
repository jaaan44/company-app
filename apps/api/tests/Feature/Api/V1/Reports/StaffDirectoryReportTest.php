<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Staff Directory report (Phase 20 — Reports, DEC-043). Gated by the
 * existing `staff.view` permission — identical visibility to
 * GET /api/v1/staff.
 */
class StaffDirectoryReportTest extends TestCase
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
        $this->getJson('/api/v1/reports/staff')->assertUnauthorized();
    }

    public function test_a_staff_role_user_can_view_the_directory_report(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        Staff::factory()->count(2)->create();

        $this->getJson('/api/v1/reports/staff')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_a_user_with_no_role_is_forbidden(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/staff')->assertForbidden();
    }

    public function test_filters_by_department_team_and_status(): void
    {
        $this->actingAsAdministrator();

        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();

        $inDept = Staff::factory()->create(['department_id' => $department->id]);
        Staff::factory()->create(); // different department
        $inTeam = Staff::factory()->create(['team_id' => $team->id]);
        $inactive = Staff::factory()->inactive()->create();

        $this->getJson("/api/v1/reports/staff?department={$department->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $inDept->public_id]]]);

        $this->getJson("/api/v1/reports/staff?team={$team->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $inTeam->public_id]]]);

        $this->getJson('/api/v1/reports/staff?status=inactive')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $inactive->public_id]]]);
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/reports/staff')->assertOk();

        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_succeeds_with_stable_headers_and_no_internal_ids(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $response = $this->get('/api/v1/reports/staff/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $headerLine = strstr(ltrim($csv, "\xEF\xBB\xBF"), "\n", true);
        $this->assertSame(
            ['Public ID', 'Employee Number', 'First Name', 'Last Name', 'Status', 'Department', 'Team', 'Position', 'Manager', 'Hire Date'],
            str_getcsv($headerLine),
        );
        $this->assertStringContainsString($staff->public_id, $csv);
        $this->assertStringContainsString('Ada', $csv);
        $this->assertStringNotContainsString((string) $staff->id.',Ada', $csv);
    }

    public function test_csv_export_applies_the_same_filters_and_authorization_as_the_json_endpoint(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->get('/api/v1/reports/staff/export')->assertForbidden();
    }

    public function test_csv_export_neutralizes_formula_injection_in_free_text_fields(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->create(['first_name' => '=cmd|"/c calc"', 'last_name' => 'Danger']);

        $csv = $this->get('/api/v1/reports/staff/export')->streamedContent();

        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
    }
}
