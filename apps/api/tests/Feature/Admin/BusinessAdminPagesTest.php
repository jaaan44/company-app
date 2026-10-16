<?php

namespace Tests\Feature\Admin;

use App\Enums\IncidentReportStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\ServiceReportStatus;
use App\Livewire\Admin\AuditLog as AuditLogPage;
use App\Livewire\Admin\IncidentRecord;
use App\Livewire\Admin\LeaveBalances;
use App\Livewire\Admin\LeaveRequestForm;
use App\Livewire\Admin\LeaveRequestRecord;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Admin\LeaveTypes;
use App\Livewire\Admin\OperationsReports;
use App\Livewire\Admin\ReportView;
use App\Livewire\Admin\ScheduleEntries;
use App\Livewire\Admin\ScheduleEntryForm;
use App\Livewire\Admin\ServiceReportRecord;
use App\Livewire\Admin\WorkLogs;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ScheduleEntry;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\Admin\Reports;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin Backoffice → HR, Work, Operations and Reports (Phases 36B–36D,
 * DEC-066).
 */
class BusinessAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Staff $lia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-16 02:00:00', 'UTC'));
        $this->admin = User::factory()->administrator()->create(['name' => 'Ada Admin']);
        $this->lia = Staff::factory()->create(['first_name' => 'Lia', 'last_name' => 'Ramos', 'preferred_name' => null, 'employee_number' => 'E-001']);
        $this->actingAs($this->admin, 'web');
    }

    private const PAGES = [
        '/admin/hr/leave-requests', '/admin/hr/leave-requests/new', '/admin/hr/leave-types', '/admin/hr/leave-balances',
        '/admin/work/work-logs', '/admin/work/schedule', '/admin/work/schedule/new',
        '/admin/operations/service-reports', '/admin/operations/incidents',
        '/admin/reports/staff', '/admin/reports/projects', '/admin/reports/tasks', '/admin/reports/work-logs',
        '/admin/reports/leave', '/admin/reports/service-reports', '/admin/reports/incidents',
    ];

    // ---- Access -----------------------------------------------------------

    public function test_only_administrators_open_the_new_screens(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertOk();
        }
        $this->get('/admin/reports/nonsense')->assertNotFound();

        foreach ([User::factory()->manager()->create(), User::factory()->staff()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            foreach (self::PAGES as $page) {
                $this->actingAs($user, 'web')->get($page)->assertForbidden();
            }
        }
        $this->app['auth']->forgetGuards();
        $this->get('/admin/hr/leave-requests')->assertRedirect('/login');
    }

    // ---- HR ---------------------------------------------------------------

    public function test_leave_requests_list_pending_first_and_filter(): void
    {
        $annual = LeaveType::factory()->create(['name' => 'Annual']);
        LeaveRequest::factory()->for($this->lia)->for($annual)->create(['reason' => 'Pending one']);
        LeaveRequest::factory()->approved()->for(Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]))->for($annual)->create();

        Livewire::test(LeaveRequests::class)
            ->assertSee('Lia Ramos')->assertDontSee('Kai Tan')->assertSee('Request leave for someone')
            ->set('status', '')->assertSee('Kai Tan')
            ->set('status', 'approved')->assertSee('Kai Tan')->assertDontSee('Lia Ramos');
    }

    public function test_a_leave_request_is_approved_rejected_or_cancelled_with_its_text(): void
    {
        $pending = LeaveRequest::factory()->for($this->lia)->create();
        Livewire::test(LeaveRequestRecord::class, ['publicId' => $pending->public_id])
            ->assertSee('Approve')->assertSee('Reject')->assertSee('Cancel request')
            ->call('ask', 'reject')->call('confirm')->assertHasErrors(['text' => 'Give a reason (at least 3 characters).'])
            ->call('ask', 'approve')->set('text', 'Enjoy')->call('confirm')
            ->assertSet('savedMessage', 'Request approved.')->assertSee('Enjoy');
        $this->assertSame(LeaveRequestStatus::Approved, $pending->fresh()->status);

        Livewire::test(LeaveRequestRecord::class, ['publicId' => $pending->public_id])
            ->assertDontSee('Reject')->call('ask', 'cancel')->call('confirm')->assertSet('savedMessage', 'Request cancelled.');
        $this->assertSame(LeaveRequestStatus::Cancelled, $pending->fresh()->status);

        Livewire::test(LeaveRequestRecord::class, ['publicId' => $pending->public_id])->assertDontSee('Cancel request')
            ->call('ask', 'cancel')->call('confirm')->assertSee('This leave request is no longer active and cannot be cancelled.');
    }

    public function test_leave_is_requested_for_someone(): void
    {
        $unpaid = LeaveType::factory()->unpaid()->create(['name' => 'Unpaid leave']);
        Livewire::test(LeaveRequestForm::class)
            ->call('save')->assertHasErrors(['staffSearch' => 'Choose the person.', 'typeId' => 'Choose a type of leave.', 'reason'])
            ->set('staffSearch', 'lia')->assertSee('Lia Ramos')
            ->call('chooseStaff', $this->lia->public_id, 'Lia Ramos')
            ->set('typeId', $unpaid->public_id)->set('startDate', '2026-11-02')->set('endDate', '2026-11-03')->set('reason', 'Family matter')
            ->call('save')->assertHasNoErrors();

        $request = LeaveRequest::query()->where('staff_id', $this->lia->id)->sole();
        $this->assertSame(LeaveRequestStatus::Pending, $request->status);
        $this->assertSame($this->admin->id, $request->created_by_user_id);
    }

    public function test_leave_types_add_edit_and_remove(): void
    {
        $page = Livewire::test(LeaveTypes::class)
            ->call('add')->set('name', 'Sick leave')->set('code', 'SL')->call('save')->assertSet('savedMessage', 'Leave type added.')
            ->assertSee('Sick leave')->assertSee('Paid — yearly allowance');
        $sick = LeaveType::query()->where('name', 'Sick leave')->sole();

        $page->call('edit', $sick->public_id)->set('isPaid', false)->set('status', 'inactive')->call('save')
            ->assertSee('Unpaid — no balance')->assertSee('Inactive');

        LeaveRequest::factory()->for($sick)->create();
        $page->call('remove', $sick->public_id)
            ->assertSee('This leave type still has leave requests referencing it and cannot be deleted. To stop it being used, set it to Inactive instead.');
        $this->assertModelExists($sick);

        $spare = LeaveType::factory()->create(['name' => 'Spare']);
        $page->call('remove', $spare->public_id)->assertSet('savedMessage', 'Leave type removed.');
        $this->assertModelMissing($spare);
    }

    public function test_leave_balances_show_and_set_an_allowance(): void
    {
        $annual = LeaveType::factory()->create(['name' => 'Annual']);
        LeaveType::factory()->unpaid()->create(['name' => 'Unpaid']);

        Livewire::withQueryParams(['staff' => $this->lia->public_id])->test(LeaveBalances::class)
            ->assertSet('year', 2026)->assertSet('staffName', 'Lia Ramos')
            ->assertSee('Annual')->assertSee('Unpaid — no balance')
            ->set('allocated.'.$annual->public_id, 'many')->call('saveAllowance', $annual->public_id)
            ->assertHasErrors(['allocated.'.$annual->public_id => 'Use a whole number of days, 0 to 365.'])
            ->set('allocated.'.$annual->public_id, '15')->call('saveAllowance', $annual->public_id)
            ->assertSet('savedMessage', 'Allowance saved.')->assertSee('15 left');

        $this->assertSame(15, LeaveBalance::query()->where('staff_id', $this->lia->id)->where('year', 2026)->sole()->allocated_days);
    }

    // ---- Work -------------------------------------------------------------

    public function test_work_logs_filter_correct_and_remove(): void
    {
        $log = WorkLog::factory()->for($this->lia)->create(['work_date' => '2026-10-15', 'duration_minutes' => 90, 'description' => 'Fixed the chiller']);
        WorkLog::factory()->create(['description' => 'Someone else']);

        $page = Livewire::test(WorkLogs::class)->assertSee('Fixed the chiller')->assertSee('Someone else')
            ->call('chooseStaff', $this->lia->public_id, 'Lia Ramos')->assertSee('Fixed the chiller')->assertDontSee('Someone else')
            ->call('edit', $log->public_id)->assertSet('hours', '1')->assertSet('minutes', '30')
            ->set('hours', '2')->set('minutes', '0')->set('description', 'Fixed the chiller and the fan')->call('save')
            ->assertSet('savedMessage', 'Work log corrected.');
        $this->assertSame(120, $log->fresh()->duration_minutes);

        $page->call('edit', $log->public_id)->set('workDate', '2026-12-01')->call('save')
            ->assertHasErrors(['work_date' => 'The work date cannot be later than today.']);
        $this->assertSame('2026-10-15', $log->fresh()->work_date->toDateString());

        $page->call('cancel')->call('remove', $log->public_id)->assertSet('savedMessage', 'Work log removed.');
        $this->assertModelMissing($log);
    }

    public function test_schedule_entries_need_a_staff_profile_to_create_then_edit_and_delete(): void
    {
        $page = Livewire::test(ScheduleEntryForm::class)->set('title', 'Safety briefing')->call('save');
        $this->assertNotNull($page->get('generalError'));
        $this->assertSame(0, ScheduleEntry::query()->count());

        $mine = Staff::factory()->create(['user_id' => $this->admin->id]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin->fresh(), 'web');
        Livewire::test(ScheduleEntryForm::class)
            ->set('title', 'Safety briefing')->set('activityType', 'training')
            ->set('startDate', '2026-10-20')->set('startTime', '09:00')->set('endDate', '2026-10-20')->set('endTime', '10:30')
            ->call('chooseStaff', $this->lia->public_id, 'Lia Ramos')->assertSee('Lia Ramos')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.schedule'));

        $entry = ScheduleEntry::query()->sole();
        $this->assertSame($mine->id, $entry->creator_staff_id);
        $this->assertSame('2026-10-20 01:00:00', $entry->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($entry->participants()->whereKey($this->lia->id)->exists());

        Livewire::test(ScheduleEntries::class)->set('from', '2026-10-01')->assertSee('Safety briefing')->assertSee('09:00 – 10:30');
        Livewire::test(ScheduleEntryForm::class, ['publicId' => $entry->public_id])
            ->assertSet('startTime', '09:00')->set('title', 'Fire safety briefing')->call('save')->assertHasNoErrors();
        $this->assertSame('Fire safety briefing', $entry->fresh()->title);

        Livewire::test(ScheduleEntryForm::class, ['publicId' => $entry->public_id])->call('delete')->assertRedirect(route('admin.schedule'));
        $this->assertModelMissing($entry);
    }

    // ---- Operations -------------------------------------------------------

    public function test_service_reports_are_reviewed_or_rejected(): void
    {
        $waiting = ServiceReport::factory()->submitted()->create(['creator_staff_id' => $this->lia->id, 'client_id' => Client::factory()->create(['name' => 'Harbour Foods'])->id]);
        ServiceReport::factory()->reviewed()->create(['client_id' => Client::factory()->create(['name' => 'Done Corp'])->id]);

        Livewire::test(OperationsReports::class, ['kind' => 'service'])->assertSee('Harbour Foods')->assertDontSee('Done Corp')
            ->set('status', 'all')->assertSee('Done Corp');

        Livewire::test(ServiceReportRecord::class, ['publicId' => $waiting->public_id])
            ->call('ask', 'reject')->call('confirm')->assertHasErrors(['text'])
            ->call('ask', 'reject')->set('text', 'Missing the readings')->call('confirm')->assertSet('savedMessage', 'Report rejected.')
            ->assertSee('Missing the readings')->assertDontSee('Mark reviewed');
        $this->assertSame(ServiceReportStatus::Rejected, $waiting->fresh()->status);

        $other = ServiceReport::factory()->submitted()->create();
        Livewire::test(ServiceReportRecord::class, ['publicId' => $other->public_id])
            ->call('ask', 'review')->call('confirm')->assertSet('savedMessage', 'Report marked as reviewed.');
        $this->assertSame(ServiceReportStatus::Reviewed, $other->fresh()->status);
    }

    public function test_an_incident_goes_through_its_workflow(): void
    {
        $incident = IncidentReport::factory()->create(['description' => 'Wet floor near the dock', 'immediate_action_taken' => null, 'corrective_action' => null]);
        IncidentReport::factory()->closed()->create(['description' => 'An old closed one']);
        IncidentReport::factory()->create(['description' => 'Being looked at', 'status' => IncidentReportStatus::UnderInvestigation]);

        // "Open" (the default) is both reported and under investigation.
        Livewire::test(OperationsReports::class, ['kind' => 'incidents'])
            ->assertSee('Wet floor near the dock')->assertSee('Being looked at')->assertDontSee('An old closed one')
            ->assertSee('2 reports');

        $page = Livewire::test(IncidentRecord::class, ['publicId' => $incident->public_id])
            ->assertSee('Assign')->assertSee('Start investigation')
            ->call('ask', 'assign')->call('confirm')->assertHasErrors(['staffSearch' => 'Choose who handles it.'])
            ->call('chooseStaff', $this->lia->public_id, 'Lia Ramos')->call('confirm')->assertSet('savedMessage', 'Assigned to Lia Ramos.')
            ->call('ask', 'start-investigation')->call('confirm')->assertSet('savedMessage', 'Investigation started.');
        $this->assertSame(IncidentReportStatus::UnderInvestigation, $incident->fresh()->status);
        $this->assertSame($this->lia->id, $incident->fresh()->assigned_to_staff_id);

        // The API needs a corrective (or immediate) action to resolve.
        $page->call('ask', 'resolve')->set('resolution', 'Mats laid')->call('confirm')
            ->assertHasErrors(['corrective_action'])->assertSet('confirming', 'resolve')
            ->set('correctiveAction', 'Anti-slip mats at the dock')->call('confirm')->assertSet('savedMessage', 'Incident resolved.')
            ->call('ask', 'close')->set('note', 'Checked')->call('confirm')->assertSet('savedMessage', 'Incident closed.')
            ->assertSee('Reopen')->assertDontSee('>Resolve<', false);
        $this->assertSame(IncidentReportStatus::Closed, $incident->fresh()->status);
        $this->assertSame('Mats laid', $incident->fresh()->resolution);

        $page->call('ask', 'reopen')->call('confirm')->assertSet('savedMessage', 'Incident reopened.');
        $this->assertNotSame(IncidentReportStatus::Closed, $incident->fresh()->status);
    }

    // ---- Reports ----------------------------------------------------------

    public function test_every_report_shows_rows_and_filters(): void
    {
        WorkLog::factory()->for($this->lia)->create(['work_date' => '2026-10-15', 'duration_minutes' => 60]);
        LeaveRequest::factory()->for($this->lia)->create();
        ServiceReport::factory()->submitted()->create();
        IncidentReport::factory()->create(['severity' => 'high']);
        Task::factory()->create();

        foreach (array_keys(Reports::all()) as $report) {
            Livewire::test(ReportView::class, ['report' => $report])->assertSee('Download CSV')->assertDontSee('Nothing matches these filters.');
        }

        Livewire::test(ReportView::class, ['report' => 'staff'])->assertSee('Lia Ramos')
            ->set('filters.status', 'separated')->assertSee('Nothing matches these filters.');
        Livewire::test(ReportView::class, ['report' => 'incidents'])->set('filters.severity', 'low')->assertSee('Nothing matches these filters.');
    }

    public function test_download_csv_streams_the_apis_export_with_the_filters_and_audits_it(): void
    {
        WorkLog::factory()->for($this->lia)->create(['work_date' => '2026-10-15', 'description' => 'In range']);
        WorkLog::factory()->for($this->lia)->create(['work_date' => '2026-09-01', 'description' => 'Out of range']);

        $response = $this->get(route('admin.reports.export', ['report' => 'work-logs', 'from' => '2026-10-01', 'bogus' => 'x']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('In range', $csv);
        $this->assertStringNotContainsString('Out of range', $csv);
        $this->assertSame('work-logs', AuditLog::query()->where('action', 'report.exported')->sole()->after['report']);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->manager()->create(), 'web')->get(route('admin.reports.export', ['report' => 'staff']))->assertForbidden();
    }

    public function test_the_audit_log_filters_by_record_person_and_date(): void
    {
        $ben = User::factory()->administrator()->create(['name' => 'Ben Admin']);
        AuditLog::factory()->create(['action' => 'staff.updated', 'entity_public_id' => 'REC1', 'actor_user_id' => $this->admin->id, 'created_at' => '2026-10-15 03:00:00']);
        AuditLog::factory()->create(['action' => 'client.updated', 'entity_public_id' => 'REC2', 'actor_user_id' => $ben->id, 'created_at' => '2026-10-01 03:00:00']);

        Livewire::test(AuditLogPage::class)->assertSee('REC1')->assertSee('REC2')
            ->call('filterRecord', 'REC1')->assertSee('REC1')->assertDontSee('REC2')
            ->call('clearFilters')->call('filterActor', $ben->public_id, 'Ben Admin')->assertSee('Only changes by')->assertSee('REC2')->assertDontSee('REC1')
            ->call('clearFilters')->set('from', '2026-10-10')->assertSee('REC1')->assertDontSee('REC2');
    }
}
