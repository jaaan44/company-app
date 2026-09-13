<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Work Logs report (Phase 20 — Reports, DEC-043). No `can:` route
 * middleware — visibility mirrors WorkLogController's own supervisory
 * surface (App\Services\Reporting\WorkLogVisibility), plus a plain-Staff
 * own-records fallback.
 */
class WorkLogReportTest extends TestCase
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
        $this->getJson('/api/v1/reports/work-logs')->assertUnauthorized();
    }

    public function test_administrator_sees_every_work_log(): void
    {
        $this->actingAsAdministrator();
        WorkLog::factory()->count(3)->create();

        $this->getJson('/api/v1/reports/work-logs')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_ordinary_staff_member_sees_only_their_own_work_logs(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mine = WorkLog::factory()->create(['staff_id' => $staff->id]);
        WorkLog::factory()->create(); // someone else's

        $this->getJson('/api/v1/reports/work-logs')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $mine->public_id]]]);
    }

    public function test_a_manager_sees_only_their_direct_reports_work_logs(): void
    {
        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        Sanctum::actingAs($managerUser);

        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        $reportLog = WorkLog::factory()->create(['staff_id' => $report->id]);
        WorkLog::factory()->create(); // unrelated

        $this->getJson('/api/v1/reports/work-logs')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $reportLog->public_id]]]);
    }

    public function test_filters_by_staff_project_task_and_date_range(): void
    {
        $this->actingAsAdministrator();

        $staff = Staff::factory()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create();

        $byStaff = WorkLog::factory()->create(['staff_id' => $staff->id, 'work_date' => '2026-05-01']);
        $byProject = WorkLog::factory()->create(['project_id' => $project->id, 'work_date' => '2026-05-01']);
        $byTask = WorkLog::factory()->create(['task_id' => $task->id, 'project_id' => null, 'work_date' => '2026-05-01']);
        WorkLog::factory()->create(['work_date' => '2020-01-01']); // outside range

        $this->getJson("/api/v1/reports/work-logs?staff={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byStaff->public_id]]]);

        $this->getJson("/api/v1/reports/work-logs?project={$project->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byProject->public_id]]]);

        $this->getJson("/api/v1/reports/work-logs?task={$task->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byTask->public_id]]]);

        $this->getJson('/api/v1/reports/work-logs?from=2026-01-01&to=2026-12-31')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        WorkLog::factory()->create();

        $this->getJson('/api/v1/reports/work-logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_matches_json_filters_and_authorization_with_no_internal_ids(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id, 'first_name' => 'Grace']);
        Sanctum::actingAs($user);

        $mine = WorkLog::factory()->create(['staff_id' => $staff->id, 'description' => 'Reviewed logs']);
        WorkLog::factory()->create(); // someone else's, must not appear

        $csv = $this->get('/api/v1/reports/work-logs/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Public ID,Staff Public ID', $csv);
        $this->assertStringContainsString($mine->public_id, $csv);
        $this->assertStringContainsString('Grace', $csv);
        $this->assertSame(2, substr_count($csv, "\n")); // header + exactly one data row
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        WorkLog::factory()->create(['description' => '=1+1']);

        $csv = $this->get('/api/v1/reports/work-logs/export')->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
    }
}
