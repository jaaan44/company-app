<?php

namespace Tests\Feature\Api\V1\Dashboard;

use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ScheduleEntry;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Admin Dashboard (Phase 20 — Admin Dashboard & Reporting, DEC-043).
 * See docs/phases/V1_PHASE_20_DEFINITION.md.
 */
class DashboardTest extends TestCase
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

    /**
     * @return array{0: User, 1: Staff}
     */
    private function actingAsManager(): array
    {
        $user = User::factory()->manager()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return [$user, $staff];
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

    // --- Authentication -----------------------------------------------

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    // --- Period defaulting ----------------------------------------------

    public function test_period_defaults_to_the_current_calendar_month_when_from_and_to_are_omitted(): void
    {
        $this->actingAsAdministrator();

        $now = Carbon::now('UTC');

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'period' => [
                        'from' => $now->copy()->startOfMonth()->toDateString(),
                        'to' => $now->copy()->endOfMonth()->toDateString(),
                        'source' => 'default_current_month',
                    ],
                ],
            ]);
    }

    public function test_an_explicit_period_is_honored_and_reported_as_explicit(): void
    {
        $this->actingAsAdministrator();

        $this->getJson('/api/v1/dashboard?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'period' => ['from' => '2026-01-01', 'to' => '2026-01-31', 'source' => 'explicit'],
                ],
            ]);
    }

    public function test_to_before_from_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->getJson('/api/v1/dashboard?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable();
    }

    public function test_period_based_metrics_use_a_company_timezone_calendar_month_boundary(): void
    {
        config(['scheduling.company_timezone' => 'Pacific/Kiritimati']); // UTC+14 — a boundary far from UTC
        $this->actingAsAdministrator();

        $now = Carbon::now('Pacific/Kiritimati');

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.from', $now->copy()->startOfMonth()->toDateString())
            ->assertJsonPath('data.period.to', $now->copy()->endOfMonth()->toDateString());
    }

    // --- People / Clients (point-in-time, company-wide for any holder) --

    public function test_active_staff_and_client_counts_are_point_in_time_and_company_wide(): void
    {
        $this->actingAsAdministrator();

        Staff::factory()->count(3)->create();
        Staff::factory()->inactive()->create();
        Client::factory()->count(2)->create();
        Client::factory()->inactive()->create();

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        // 3 created above + the Administrator's own Staff-less account
        // contributes nothing (no linked Staff record) — exactly 3
        // active Staff.
        $response->assertJsonPath('data.people.active_staff_count', 3);
        $response->assertJsonPath('data.clients.active_clients_count', 2);
    }

    public function test_a_staff_member_sees_the_same_company_wide_staff_and_client_counts_as_administrator(): void
    {
        $this->actingAsStaffMember();

        Staff::factory()->count(2)->create();
        Client::factory()->count(4)->create();

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            // +1 for the acting Staff member's own record.
            ->assertJsonPath('data.people.active_staff_count', 3)
            ->assertJsonPath('data.clients.active_clients_count', 4);
    }

    public function test_a_user_with_no_role_sees_zero_staff_and_client_counts_never_a_default_company_wide_view(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        Staff::factory()->count(5)->create();
        Client::factory()->count(5)->create();

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.people.active_staff_count', 0)
            ->assertJsonPath('data.clients.active_clients_count', 0);
    }

    // --- Projects (point-in-time; Manager company-wide vs Staff scoped) -

    public function test_a_manager_sees_every_project_by_status(): void
    {
        $this->actingAsManager();

        Project::factory()->active()->count(2)->create();
        Project::factory()->completed()->create();

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.projects.active_projects_count', 2);
        $response->assertJsonPath('data.projects.by_status.active', 2);
        $response->assertJsonPath('data.projects.by_status.completed', 1);
    }

    public function test_an_ordinary_staff_member_sees_only_projects_they_belong_to_never_a_company_wide_count(): void
    {
        [, $staff] = $this->actingAsStaffMember();

        $myProject = Project::factory()->active()->create();
        ProjectMembership::factory()->for($myProject, 'project')->for($staff, 'staff')->create();

        // Two Projects this Staff member has no membership on.
        Project::factory()->active()->count(2)->create();

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.projects.active_projects_count', 1)
            ->assertJsonPath('data.projects.by_status.active', 1);
    }

    // --- Tasks (point-in-time; overdue canonical definition) ------------

    public function test_task_status_breakdown_and_overdue_count_for_administrator(): void
    {
        $this->actingAsAdministrator();

        Task::factory()->count(2)->create(); // todo, no due date
        Task::factory()->inProgress()->create(['due_date' => now()->subDays(3)]); // overdue
        Task::factory()->completed()->create(['due_date' => now()->subDays(5)]); // NOT overdue (terminal)
        Task::factory()->create(['due_date' => now()->addDays(3)]); // not yet due

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.tasks.by_status.todo', 3);
        $response->assertJsonPath('data.tasks.by_status.in_progress', 1);
        $response->assertJsonPath('data.tasks.by_status.completed', 1);
        $response->assertJsonPath('data.tasks.overdue_count', 1);
    }

    public function test_overdue_count_uses_the_company_timezone_for_today_not_the_server_default(): void
    {
        config(['scheduling.company_timezone' => 'Pacific/Kiritimati']); // UTC+14
        $this->actingAsAdministrator();

        // "Now" in UTC may already be "tomorrow" in UTC+14 — a due_date of
        // UTC-today should NOT be overdue once the company's own "today"
        // has rolled over past it only when genuinely earlier; here we
        // assert a due_date of company-"today" is never counted overdue.
        $companyToday = Carbon::now('Pacific/Kiritimati')->toDateString();
        Task::factory()->create(['due_date' => $companyToday]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.tasks.overdue_count', 0);
    }

    public function test_task_metrics_never_change_because_a_historical_period_was_supplied(): void
    {
        $this->actingAsAdministrator();

        Task::factory()->inProgress()->create(['due_date' => now()->subDays(3)]);

        $withoutPeriod = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.tasks');
        $withPeriod = $this->getJson('/api/v1/dashboard?from=2020-01-01&to=2020-01-31')->assertOk()->json('data.tasks');

        $this->assertSame($withoutPeriod, $withPeriod);
    }

    public function test_a_staff_member_only_sees_tasks_they_are_assigned_to_or_in_a_member_project(): void
    {
        [, $staff] = $this->actingAsStaffMember();

        Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Task::factory()->count(3)->create(); // unrelated

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.tasks.by_status.todo', 1);
    }

    // --- Work Logs (period-based; operational activity only) ------------

    public function test_work_log_total_hours_for_administrator_sums_every_log_in_period(): void
    {
        $this->actingAsAdministrator();

        WorkLog::factory()->create(['work_date' => now()->toDateString(), 'duration_minutes' => 120]);
        WorkLog::factory()->create(['work_date' => now()->toDateString(), 'duration_minutes' => 60]);
        WorkLog::factory()->create(['work_date' => now()->subMonths(2)->toDateString(), 'duration_minutes' => 999]); // outside period

        $response = $this->getJson('/api/v1/dashboard')->assertOk();
        $this->assertEqualsWithDelta(3.0, $response->json('data.work_logs.total_hours'), 0.001);
    }

    public function test_an_ordinary_staff_member_sees_only_their_own_work_log_hours(): void
    {
        [, $staff] = $this->actingAsStaffMember();

        WorkLog::factory()->create(['staff_id' => $staff->id, 'work_date' => now()->toDateString(), 'duration_minutes' => 90]);
        WorkLog::factory()->create(['work_date' => now()->toDateString(), 'duration_minutes' => 600]); // someone else's

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.work_logs.total_hours', 1.5);
    }

    public function test_a_manager_sees_only_direct_reports_work_log_hours_not_company_wide(): void
    {
        [, $manager] = $this->actingAsManager();

        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        $stranger = Staff::factory()->create();

        WorkLog::factory()->create(['staff_id' => $report->id, 'work_date' => now()->toDateString(), 'duration_minutes' => 60]);
        WorkLog::factory()->create(['staff_id' => $stranger->id, 'work_date' => now()->toDateString(), 'duration_minutes' => 600]);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();
        $this->assertEqualsWithDelta(1.0, $response->json('data.work_logs.total_hours'), 0.001);
    }

    // --- Leave ------------------------------------------------------------

    public function test_leave_pending_is_point_in_time_and_approved_is_period_based(): void
    {
        $this->actingAsAdministrator();
        $leaveType = LeaveType::factory()->create();

        LeaveRequest::factory()->for($leaveType, 'leaveType')->create(); // pending
        LeaveRequest::factory()->for($leaveType, 'leaveType')->approved()->create([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
        LeaveRequest::factory()->for($leaveType, 'leaveType')->approved()->create([
            'start_date' => now()->addMonths(6)->toDateString(),
            'end_date' => now()->addMonths(6)->addDay()->toDateString(),
        ]); // outside period

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.leave.pending_count', 1)
            ->assertJsonPath('data.leave.approved_count_in_period', 1);
    }

    public function test_a_staff_member_sees_only_their_own_leave_counts(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $leaveType = LeaveType::factory()->create();

        LeaveRequest::factory()->for($leaveType, 'leaveType')->create(['staff_id' => $staff->id]);
        LeaveRequest::factory()->for($leaveType, 'leaveType')->create(); // someone else's pending request

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.leave.pending_count', 1);
    }

    // --- Service Reports ----------------------------------------------

    public function test_service_report_status_breakdown_is_period_based_by_service_date(): void
    {
        $this->actingAsAdministrator();

        ServiceReport::factory()->create(['service_date' => now()->toDateString()]);
        ServiceReport::factory()->submitted()->create(['service_date' => now()->toDateString()]);
        ServiceReport::factory()->create(['service_date' => now()->subMonths(3)->toDateString()]); // outside period

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.service_reports.by_status_in_period.draft', 1);
        $response->assertJsonPath('data.service_reports.by_status_in_period.submitted', 1);
    }

    public function test_a_stranger_sees_no_service_reports(): void
    {
        $this->actingAsStaffMember();

        ServiceReport::factory()->create(['service_date' => now()->toDateString()]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.service_reports.by_status_in_period.draft', 0);
    }

    // --- Incident Reports (privacy is the strictest visibility rule) ----

    public function test_incident_open_count_and_severity_breakdown_for_administrator(): void
    {
        $this->actingAsAdministrator();

        IncidentReport::factory()->create(['occurred_at' => now(), 'severity' => 'high']); // reported -> open
        IncidentReport::factory()->underInvestigation()->create(['occurred_at' => now(), 'severity' => 'critical']); // open
        IncidentReport::factory()->resolved()->create(['occurred_at' => now(), 'severity' => 'low']); // NOT open
        // Outside the reporting period but still resolved (not open) — this
        // isolates the assertion below to proving by_severity_in_period
        // excludes it, without also perturbing open_count (which is
        // point-in-time and would otherwise count it, since it happened
        // outside the period but is still unresolved in a different test).
        IncidentReport::factory()->resolved()->create(['occurred_at' => now()->subMonths(2), 'severity' => 'high']);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $response->assertJsonPath('data.incident_reports.open_count', 2);
        $response->assertJsonPath('data.incident_reports.by_severity_in_period.high', 1);
        $response->assertJsonPath('data.incident_reports.by_severity_in_period.critical', 1);
        $response->assertJsonPath('data.incident_reports.by_severity_in_period.low', 1);
    }

    public function test_incident_open_count_is_point_in_time_and_includes_open_incidents_outside_the_period(): void
    {
        $this->actingAsAdministrator();

        IncidentReport::factory()->create(['occurred_at' => now()->subMonths(6)]); // reported, long ago -> still open

        $this->getJson('/api/v1/dashboard?from=2020-01-01&to=2020-01-31')
            ->assertOk()
            ->assertJsonPath('data.incident_reports.open_count', 1);
    }

    public function test_a_project_lead_gains_no_incident_report_visibility_merely_from_the_linked_project(): void
    {
        [, $lead] = $this->actingAsStaffMember();
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->for($project, 'project')->for($lead, 'staff')->create();

        IncidentReport::factory()->create(['project_id' => $project->id, 'occurred_at' => now()]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.incident_reports.open_count', 0);
    }

    public function test_a_managers_direct_report_incident_is_visible_to_that_manager(): void
    {
        [, $manager] = $this->actingAsManager();

        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        IncidentReport::factory()->create(['reporter_staff_id' => $report->id, 'occurred_at' => now()]);

        // An unrelated incident this Manager has no relationship to.
        IncidentReport::factory()->create(['occurred_at' => now()]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.incident_reports.open_count', 1);
    }

    // --- Schedule (reuses the Scheduler's own unified visibility) -------

    public function test_upcoming_schedule_shows_only_entries_the_requester_could_already_see(): void
    {
        [, $staff] = $this->actingAsStaffMember();

        ScheduleEntry::factory()->for($staff, 'creator')->create([
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        // Someone else's private entry, and one outside the 7-day horizon.
        ScheduleEntry::factory()->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        ScheduleEntry::factory()->for($staff, 'creator')->create([
            'starts_at' => now()->addDays(30),
            'ends_at' => now()->addDays(30)->addHour(),
        ]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.schedule.upcoming_count', 1)
            ->assertJsonCount(1, 'data.schedule.items');
    }

    public function test_the_upcoming_schedule_horizon_is_always_the_next_seven_days_regardless_of_dashboard_period(): void
    {
        $this->actingAsAdministrator();

        $response = $this->getJson('/api/v1/dashboard?from=2020-01-01&to=2020-01-31')->assertOk();

        $now = Carbon::now('UTC');
        $this->assertSame($now->toDateString(), Carbon::parse($response->json('data.schedule.horizon.from'))->toDateString());
        $this->assertSame(
            $now->copy()->addDays(7)->toDateString(),
            Carbon::parse($response->json('data.schedule.horizon.to'))->toDateString(),
        );
    }
}
