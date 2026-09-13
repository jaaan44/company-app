<?php

namespace Tests\Feature\Api\V1\Scheduling;

use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleAggregationTest extends TestCase
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

    // --- from/to requirement -----------------------------------------------

    public function test_from_and_to_are_required(): void
    {
        $this->actingAsAdministrator();

        $this->getJson('/api/v1/schedule')
            ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_to_must_not_be_before_from(): void
    {
        $this->actingAsAdministrator();

        $this->getJson('/api/v1/schedule?from=2026-11-10&to=2026-11-01')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    // --- Source discriminator / no internal ids -----------------------------

    public function test_response_shape_carries_the_source_type_discriminator_and_no_internal_ids(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $staff->id,
            'starts_at' => '2026-11-05 09:00:00',
            'ends_at' => '2026-11-05 10:00:00',
        ]);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['source_type' => 'schedule_entry']]])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_task_due_dates_are_aggregated_as_read_only_items(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['title' => 'File the report', 'due_date' => '2026-11-15']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJson(['data' => [['source_type' => 'task', 'title' => 'File the report', 'is_all_day' => true]]]);
    }

    public function test_approved_leave_is_aggregated(): void
    {
        $this->actingAsAdministrator();
        LeaveRequest::factory()->approved()->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJson(['data' => [['source_type' => 'leave', 'status' => 'approved']]]);
    }

    public function test_non_approved_leave_is_never_aggregated(): void
    {
        $this->actingAsAdministrator();
        LeaveRequest::factory()->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']); // pending
        LeaveRequest::factory()->rejected()->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);
        LeaveRequest::factory()->cancelled()->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJsonCount(0, 'data');
    }

    public function test_milestones_are_aggregated(): void
    {
        $this->actingAsAdministrator();
        ProjectMilestone::factory()->create(['title' => 'Beta release', 'due_date' => '2026-11-20']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJson(['data' => [['source_type' => 'project_milestone', 'title' => 'Beta release']]]);
    }

    public function test_all_four_sources_can_appear_together_sorted_by_start(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ScheduleEntry::factory()->create(['starts_at' => '2026-11-20 09:00:00', 'ends_at' => '2026-11-20 10:00:00', 'title' => 'D']);
        Task::factory()->create(['due_date' => '2026-11-10', 'title' => 'B']);
        LeaveRequest::factory()->approved()->create(['start_date' => '2026-11-05', 'end_date' => '2026-11-06']);
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'due_date' => '2026-11-15', 'title' => 'C']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $sourceTypes = collect($response->json('data'))->pluck('source_type')->all();
        $this->assertSame(['leave', 'task', 'project_milestone', 'schedule_entry'], $sourceTypes);
    }

    // --- Date-range boundaries --------------------------------------------

    public function test_items_outside_the_requested_range_are_excluded(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['due_date' => '2026-10-31', 'title' => 'Too early']);
        Task::factory()->create(['due_date' => '2026-12-01', 'title' => 'Too late']);
        Task::factory()->create(['due_date' => '2026-11-15', 'title' => 'In range']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJson(['data' => [['title' => 'In range']]]);
    }

    public function test_items_exactly_on_the_range_boundary_are_included(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['due_date' => '2026-11-01', 'title' => 'On start boundary']);
        Task::factory()->create(['due_date' => '2026-11-30', 'title' => 'On end boundary']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    }

    // --- Filters -----------------------------------------------------------

    public function test_the_source_filter_narrows_to_one_source_type(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['due_date' => '2026-11-10']);
        ProjectMilestone::factory()->create(['due_date' => '2026-11-15']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30&source=task')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJson(['data' => [['source_type' => 'task']]]);
    }

    public function test_the_activity_type_filter_only_matches_schedule_entries(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['due_date' => '2026-11-10']);
        ScheduleEntry::factory()->create(['activity_type' => 'training', 'starts_at' => '2026-11-12 09:00:00', 'ends_at' => '2026-11-12 10:00:00', 'title' => 'Onboarding']);
        ScheduleEntry::factory()->create(['activity_type' => 'meeting', 'starts_at' => '2026-11-13 09:00:00', 'ends_at' => '2026-11-13 10:00:00']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30&activity_type=training')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJson(['data' => [['title' => 'Onboarding']]]);
    }

    public function test_the_project_filter_narrows_across_applicable_sources(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'due_date' => '2026-11-10', 'title' => 'Linked task']);
        Task::factory()->create(['due_date' => '2026-11-10', 'title' => 'Unlinked task']);
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'due_date' => '2026-11-12', 'title' => 'Linked milestone']);
        LeaveRequest::factory()->approved()->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-11']);

        $response = $this->getJson("/api/v1/schedule?from=2026-11-01&to=2026-11-30&project={$project->public_id}")->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Linked task', 'Linked milestone'], $titles);
    }

    // --- Visibility preservation --------------------------------------------

    public function test_task_visibility_rules_are_preserved_in_aggregation(): void
    {
        [, $viewer] = $this->actingAsStaffMember();
        Task::factory()->create(['due_date' => '2026-11-10', 'title' => 'Not mine']);
        Task::factory()->create(['due_date' => '2026-11-11', 'title' => 'Assigned to me', 'assignee_staff_id' => $viewer->id]);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJson(['data' => [['title' => 'Assigned to me']]]);
    }

    public function test_leave_privacy_is_preserved_a_staff_member_sees_only_their_own_and_administrator_sees_all(): void
    {
        [, $viewer] = $this->actingAsStaffMember();
        LeaveRequest::factory()->approved()->create(['staff_id' => $viewer->id, 'start_date' => '2026-11-05', 'end_date' => '2026-11-06']);
        LeaveRequest::factory()->approved()->create(['start_date' => '2026-11-07', 'end_date' => '2026-11-08']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();
        $response->assertJsonCount(1, 'data');

        $this->actingAsAdministrator();
        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_manager_sees_a_direct_reports_approved_leave(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        LeaveRequest::factory()->approved()->create(['staff_id' => $report->id, 'start_date' => '2026-11-05', 'end_date' => '2026-11-06']);

        Sanctum::actingAs($managerUser);

        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_milestone_visibility_rules_are_preserved_in_aggregation(): void
    {
        $project = Project::factory()->create();
        [$user, $staff] = $this->actingAsStaffMember();
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'due_date' => '2026-11-10', 'title' => 'Not visible']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();
        $response->assertJsonCount(0, 'data');

        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_schedule_entry_visibility_rules_are_preserved_in_aggregation(): void
    {
        [, $viewer] = $this->actingAsStaffMember();
        ScheduleEntry::factory()->create(['starts_at' => '2026-11-05 09:00:00', 'ends_at' => '2026-11-05 10:00:00']);

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')->assertOk();

        $response->assertJsonCount(0, 'data');
    }

    // --- Pagination / response shape ----------------------------------------

    public function test_the_response_uses_the_standard_pagination_shape(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->count(3)->sequence(
            ['due_date' => '2026-11-05'],
            ['due_date' => '2026-11-10'],
            ['due_date' => '2026-11-15'],
        )->create();

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30&per_page=2')->assertOk();

        $response->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_a_second_page_returns_the_remaining_items(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->count(3)->sequence(
            ['due_date' => '2026-11-05'],
            ['due_date' => '2026-11-10'],
            ['due_date' => '2026-11-15'],
        )->create();

        $response = $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30&per_page=2&page=2')->assertOk();

        $response->assertJsonCount(1, 'data');
    }

    public function test_an_unknown_project_filter_returns_an_empty_result_not_an_error(): void
    {
        $this->actingAsAdministrator();
        Task::factory()->create(['due_date' => '2026-11-10']);

        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30&project=not-a-real-ulid')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // --- No calendar-row duplication -----------------------------------------

    public function test_updating_the_source_task_is_reflected_live_never_a_stale_copy(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create(['due_date' => '2026-11-10', 'title' => 'Original title']);

        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')
            ->assertJson(['data' => [['title' => 'Original title']]]);

        $task->update(['title' => 'Renamed']);

        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-11-30')
            ->assertJson(['data' => [['title' => 'Renamed']]]);
    }
}
