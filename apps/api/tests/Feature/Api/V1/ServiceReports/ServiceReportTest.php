<?php

namespace Tests\Feature\Api\V1\ServiceReports;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creation, structural validation, relational coherence, creation
 * authority/eligibility, filters, and API shape (Phase 18 — Service
 * Reports). See docs/phases/V1_PHASE_18_DEFINITION.md and DEC-041.
 */
class ServiceReportTest extends TestCase
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

    // --- Creation ---------------------------------------------------------

    public function test_a_staff_linked_user_can_create_a_service_report_for_a_client(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $client = Client::factory()->create();

        $response = $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'Replaced the air filter and inspected the compressor.',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'client' => ['public_id' => $client->public_id],
                    'creator' => ['public_id' => $staff->public_id],
                    'status' => 'draft',
                    'service_date' => '2026-09-10',
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('service_reports', ['client_id' => $client->id, 'creator_staff_id' => $staff->id]);
    }

    public function test_a_user_with_no_linked_staff_record_cannot_create_a_service_report(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertForbidden();
    }

    public function test_an_inactive_staff_member_cannot_self_create_a_service_report(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->inactive()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $client = Client::factory()->create();

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertForbidden();
    }

    public function test_creating_a_service_report_requires_client_service_date_and_work_performed(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/service-reports', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'service_date', 'work_performed']);
    }

    public function test_a_future_service_date_is_rejected(): void
    {
        $this->actingAsStaffMember();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'service_date' => now()->addDay()->toDateString(),
            'work_performed' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_date');
    }

    public function test_administrator_can_create_a_service_report_on_behalf_of_another_staff_member(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->inactive()->create();
        $client = Client::factory()->create();

        $response = $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'creator_staff_id' => $staff->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'Backfilled historical record.',
        ]);

        $response->assertCreated()->assertJson(['data' => ['creator' => ['public_id' => $staff->public_id]]]);
    }

    public function test_a_non_administrator_cannot_create_a_service_report_for_someone_else(): void
    {
        $this->actingAsStaffMember();
        $otherStaff = Staff::factory()->create();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'creator_staff_id' => $otherStaff->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertForbidden();
    }

    // --- Relational coherence ------------------------------------------------

    public function test_a_project_belonging_to_a_different_client_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $otherClient->id]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'creator_staff_id' => Staff::factory()->create()->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_a_task_belonging_to_a_different_project_than_supplied_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $otherProject = Project::factory()->create(['client_id' => $client->id]);
        $task = Task::factory()->create(['project_id' => $otherProject->id]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'task_id' => $task->public_id,
            'creator_staff_id' => Staff::factory()->create()->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_project_id_is_derived_from_task_when_omitted_and_must_belong_to_the_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $wrongClient = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $wrongClient->id]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'task_id' => $task->public_id,
            'creator_staff_id' => Staff::factory()->create()->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_an_independent_task_combined_with_a_project_id_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $independentTask = Task::factory()->create(['project_id' => null]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'task_id' => $independentTask->public_id,
            'creator_staff_id' => Staff::factory()->create()->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }

    public function test_a_coherent_client_project_and_task_are_accepted(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $response = $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'task_id' => $task->public_id,
            'creator_staff_id' => Staff::factory()->create()->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ]);

        $response->assertCreated()->assertJson([
            'data' => ['project' => ['public_id' => $project->public_id], 'task' => ['public_id' => $task->public_id]],
        ]);
    }

    public function test_a_report_can_be_created_with_no_project_or_task(): void
    {
        $this->actingAsStaffMember();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertCreated()->assertJson(['data' => ['project' => null, 'task' => null]]);
    }

    // --- Self-service eligibility ------------------------------------------

    public function test_self_service_creation_for_a_project_requires_current_project_membership(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertForbidden();

        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertCreated();
    }

    public function test_self_service_creation_for_an_independent_task_requires_being_its_assignee(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $client = Client::factory()->create();
        $task = Task::factory()->create(['project_id' => null, 'assignee_staff_id' => null]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'task_id' => $task->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertForbidden();

        $task->update(['assignee_staff_id' => $staff->id]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'task_id' => $task->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertCreated();
    }

    public function test_administrator_bypasses_self_service_eligibility_checks(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->postJson('/api/v1/service-reports', [
            'client_id' => $client->public_id,
            'project_id' => $project->public_id,
            'creator_staff_id' => $staff->public_id,
            'service_date' => '2026-09-10',
            'work_performed' => 'X',
        ])->assertCreated();
    }

    // --- API shape / filters -----------------------------------------------

    public function test_viewing_a_service_report_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $this->getJson("/api/v1/service-reports/{$report->id}")->assertNotFound();
    }

    public function test_reports_can_be_filtered_by_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        ServiceReport::factory()->create(['client_id' => $client->id, 'work_performed' => 'Matching']);
        ServiceReport::factory()->create(['work_performed' => 'Other']);

        $this->getJson("/api/v1/service-reports?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['work_performed' => 'Matching']]]);
    }

    public function test_reports_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        ServiceReport::factory()->submitted()->create(['work_performed' => 'Submitted one']);
        ServiceReport::factory()->create(['work_performed' => 'Draft one']);

        $this->getJson('/api/v1/service-reports?status=submitted')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['work_performed' => 'Submitted one']]]);
    }

    public function test_reports_can_be_filtered_by_service_date_range(): void
    {
        $this->actingAsAdministrator();
        ServiceReport::factory()->create(['service_date' => '2026-01-05', 'work_performed' => 'In range']);
        ServiceReport::factory()->create(['service_date' => '2026-03-01', 'work_performed' => 'Out of range']);

        $this->getJson('/api/v1/service-reports?from=2026-01-01&to=2026-01-31')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['work_performed' => 'In range']]]);
    }

    public function test_reports_can_be_paginated(): void
    {
        $this->actingAsAdministrator();
        ServiceReport::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/service-reports?per_page=2')->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertArrayHasKey('links', $response->json());
        $this->assertArrayHasKey('meta', $response->json());
    }
}
