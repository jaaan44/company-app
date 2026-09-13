<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Tasks report (Phase 20 — Reports, DEC-043). No `can:` route
 * middleware — visibility mirrors TaskController's own
 * `GET /api/v1/tasks` (App\Services\Reporting\TaskVisibility). The
 * `?overdue=1` filter uses the single canonical overdue definition
 * (App\Support\Reporting\OverdueTasks), the same one the Dashboard uses.
 */
class TaskReportTest extends TestCase
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
        $this->getJson('/api/v1/reports/tasks')->assertUnauthorized();
    }

    public function test_a_manager_sees_every_task(): void
    {
        $managerUser = User::factory()->manager()->create();
        Sanctum::actingAs($managerUser);
        Task::factory()->count(3)->create();

        $this->getJson('/api/v1/reports/tasks')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_ordinary_staff_member_sees_only_assigned_or_member_project_tasks(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $assigned = Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Task::factory()->create(); // unrelated

        $this->getJson('/api/v1/reports/tasks')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $assigned->public_id]]]);
    }

    public function test_filters_by_project_assignee_status_priority_and_overdue(): void
    {
        $this->actingAsAdministrator();

        $project = Project::factory()->create();
        $staff = Staff::factory()->create();

        $byProject = Task::factory()->create(['project_id' => $project->id]);
        $byAssignee = Task::factory()->create(['assignee_staff_id' => $staff->id]);
        $urgent = Task::factory()->urgent()->create();
        $inProgress = Task::factory()->inProgress()->create();
        $overdue = Task::factory()->create(['due_date' => now()->subDays(2)]);
        Task::factory()->completed()->create(['due_date' => now()->subDays(2)]); // NOT overdue

        $this->getJson("/api/v1/reports/tasks?project={$project->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byProject->public_id]]]);

        $this->getJson("/api/v1/reports/tasks?assignee={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byAssignee->public_id]]]);

        $this->getJson('/api/v1/reports/tasks?priority=urgent')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $urgent->public_id]]]);

        $this->getJson('/api/v1/reports/tasks?status=in_progress')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $inProgress->public_id]]]);

        $this->getJson('/api/v1/reports/tasks?overdue=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $overdue->public_id]]]);
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create();

        $this->getJson('/api/v1/reports/tasks')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_matches_json_filters_and_authorization_with_no_internal_ids(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mine = Task::factory()->create(['assignee_staff_id' => $staff->id, 'title' => 'My Task']);
        Task::factory()->create(['title' => 'Not Mine']);

        $csv = $this->get('/api/v1/reports/tasks/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('My Task', $csv);
        $this->assertStringNotContainsString('Not Mine', $csv);
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['title' => '=HYPERLINK("http://evil")']);

        $csv = $this->get('/api/v1/reports/tasks/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
