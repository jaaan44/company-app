<?php

namespace Tests\Feature\Portal;

use App\Enums\LeaveRequestStatus;
use App\Enums\OperationalStatus;
use App\Livewire\Portal\Leave;
use App\Livewire\Portal\LeaveForm;
use App\Livewire\Portal\LeaveRequestShow;
use App\Livewire\Portal\MyStatus;
use App\Livewire\Portal\StatusHistory;
use App\Livewire\Portal\TeamLeave;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\StaffOperationalStatus;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff Web Portal — HR & Operations (Phase 34C): Leave (my balances
 * and requests, Request leave, Cancel; Team requests with Approve and
 * Reject) and My status (current status, Change status, history, read-
 * only check-ins), against the real API, showing what the mobile screens
 * show. "Today" is Tue 13 Oct 2026 in Manila.
 */
class LeaveStatusPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/app/leave', '/app/leave/new', '/app/leave/team', '/app/status', '/app/status/changes', '/app/status/check-ins'];

    private User $user;

    private Staff $staff;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-13 02:00:00', 'UTC')); // Tue 13 Oct, 10:00 Manila

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Lia', 'preferred_name' => null]);
        $this->vacation = LeaveType::factory()->create(['name' => 'Vacation leave', 'sort_order' => 1]);
        LeaveBalance::factory()->create(['staff_id' => $this->staff->id, 'leave_type_id' => $this->vacation->id, 'year' => 2026, 'allocated_days' => 15]);
    }

    /** @param array<string, mixed> $attributes */
    private function leave(Staff $staff, string $start, string $end, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $this->vacation->id,
            'start_date' => $start,
            'end_date' => $end,
            'total_days' => Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            ...$attributes,
        ]);
    }

    /** A manager (Ben) with Lia as a direct report. */
    private function manager(): User
    {
        $user = User::factory()->manager()->create();
        $ben = Staff::factory()->create(['user_id' => $user->id, 'first_name' => 'Ben', 'preferred_name' => null]);
        $this->staff->update(['manager_id' => $ben->id]);

        return $user;
    }

    public function test_guests_are_sent_to_login_and_every_role_can_open_the_pages(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertRedirect('/login');
        }
        foreach ([$this->user, User::factory()->manager()->create(), User::factory()->administrator()->create(), User::factory()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            foreach (self::PAGES as $page) {
                $this->actingAs($user, 'web')->get($page)->assertOk();
            }
        }
    }

    public function test_leave_and_my_status_are_on_more(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/more')->assertSeeInOrder(['My status', 'Leave']);
        foreach (['/app/leave', '/app/status'] as $url) {
            $page = $this->get($url)->assertSee('aria-label="Back to More"', false)->getContent();
            $this->assertMatchesRegularExpression('#href="[^"]*/app/more"\s+aria-current="page"#', $page);
        }
    }

    // ---- Leave overview ----------------------------------------------------

    public function test_leave_shows_balances_and_my_requests_newest_first(): void
    {
        $unpaid = LeaveType::factory()->unpaid()->create(['name' => 'Unpaid leave', 'sort_order' => 2]);
        $this->leave($this->staff, '2026-10-20', '2026-10-22');
        $this->leave($this->staff, '2026-09-01', '2026-09-02', ['status' => LeaveRequestStatus::Approved]);
        $this->leave($this->staff, '2026-12-28', '2026-12-28', ['leave_type_id' => $unpaid->id, 'status' => LeaveRequestStatus::Rejected]);
        $this->leave(Staff::factory()->create(), '2026-10-20', '2026-10-20', ['reason' => 'Someone else’s trip']);

        $this->actingAs($this->user, 'web')->get('/app/leave')->assertOk()
            ->assertSee('Request leave')
            ->assertSee('Balances 2026')
            ->assertSeeInOrder(['Vacation leave', '10 days left of 15', '2 used · 3 pending', 'Unpaid leave', 'Unpaid — no balance'])
            ->assertSee('Mon 28 Dec 2026 · 1 day')
            ->assertSee('Tue 20 Oct – Thu 22 Oct 2026 · 3 days')
            ->assertSee('Tue 1 Sep – Wed 2 Sep 2026 · 2 days')
            ->assertSeeInOrder(['Pending', 'Approved'])
            ->assertSee('Rejected')
            ->assertDontSee('Someone else')
            ->assertDontSee('Team requests');
    }

    public function test_leave_empty_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/leave')->assertSee("You haven't requested leave yet.", false);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/leave')
            ->assertSee('No staff profile is linked to this account.')
            ->assertDontSee('Request leave');
    }

    public function test_a_manager_sees_the_team_entry_with_the_pending_count(): void
    {
        $manager = $this->manager();
        $this->leave($this->staff, '2026-10-20', '2026-10-20');
        $this->leave($this->staff, '2026-10-27', '2026-10-27');

        $this->actingAs($manager, 'web')->get('/app/leave')
            ->assertSee('Team requests')->assertSee('2 pending')
            ->assertSee(route('portal.leave.team'), false);

        // A manager with no direct reports sees no entry.
        $this->app['auth']->forgetGuards();
        $lonely = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $lonely->id]);
        $this->actingAs($lonely, 'web')->get('/app/leave')->assertDontSee('Team requests');
    }

    // ---- Request leave -----------------------------------------------------

    public function test_the_form_checks_what_the_mobile_form_checks(): void
    {
        $this->actingAs($this->user, 'web');

        Livewire::test(LeaveForm::class)
            ->assertSee('Vacation leave — 15 days left in 2026')
            ->call('submit')
            ->assertHasErrors(['type', 'startDate', 'endDate', 'reason'])
            ->assertSee('Choose a type of leave.')->assertSee('Choose the first day.')->assertSee('Choose the last day.')
            ->assertSee('Give a reason (at least 3 characters).')
            ->set('startDate', '2026-12-30')->assertSet('endDate', '2026-12-30')
            ->set('endDate', '2026-12-29')->set('type', $this->vacation->public_id)->set('reason', 'ok')
            ->call('submit')
            ->assertSee('The last day cannot be before the first day.')
            ->set('endDate', '2027-01-02')
            ->call('submit')
            ->assertSee('A request must stay within one calendar year. Make one request for each year.')
            ->set('reason', str_repeat('a', 1001))
            ->call('submit')
            ->assertSee('Use at most 1000 characters.');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_the_form_shows_the_balance_and_day_count_and_sends_the_request(): void
    {
        $this->actingAs($this->user, 'web');

        Livewire::test(LeaveForm::class)
            ->assertSet('companyDate', '2026-10-13')
            ->set('type', $this->vacation->public_id)
            ->set('startDate', '2026-10-20')
            ->assertSee('15 days left in 2026')
            ->assertSee('1 calendar day (weekends included)')
            ->set('endDate', '2026-10-22')
            ->assertSee('3 calendar days (weekends included)')
            ->set('reason', '  Family trip  ')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('portal.leave'));

        $request = LeaveRequest::query()->sole();
        $this->assertSame($this->staff->id, $request->staff_id);
        $this->assertSame('2026-10-20', $request->start_date->toDateString());
        $this->assertSame('2026-10-22', $request->end_date->toDateString());
        $this->assertSame('Family trip', $request->reason);
        $this->assertSame(LeaveRequestStatus::Pending, $request->status);
        $this->assertSame('Leave requested.', session('status'));
    }

    public function test_a_conflict_from_the_api_shows_in_its_own_words(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'POST') {
                    throw new PortalApiException(409, 'Not enough Vacation leave balance for these dates.');
                }

                return match (true) {
                    $path === '/me/leave-balances' => ['data' => []],
                    default => ['data' => [], 'meta' => ['company_day' => ['date' => '2026-10-13']]],
                };
            }
        });
        $this->actingAs($this->user, 'web');

        Livewire::test(LeaveForm::class)
            ->set('type', 'X1')->set('startDate', '2026-10-20')->set('endDate', '2026-10-22')->set('reason', 'Family trip')
            ->call('submit')
            ->assertNoRedirect()
            ->assertSet('generalError', 'Not enough Vacation leave balance for these dates.')
            ->assertSee('Not enough Vacation leave balance for these dates.');
    }

    public function test_server_validation_lands_on_the_fields(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'POST') {
                    throw new PortalApiException(422, 'Invalid.', ['start_date' => ['The start date must be today or later.'], 'other' => ['Odd.']]);
                }

                return match (true) {
                    $path === '/me/leave-balances' => ['data' => []],
                    default => ['data' => [], 'meta' => ['company_day' => ['date' => '2026-10-13']]],
                };
            }
        });
        $this->actingAs($this->user, 'web');

        Livewire::test(LeaveForm::class)
            ->set('type', 'X1')->set('startDate', '2026-10-01')->set('endDate', '2026-10-01')->set('reason', 'Trip')
            ->call('submit')
            ->assertHasErrors(['startDate'])
            ->assertSee('The start date must be today or later.')
            ->assertSet('generalError', 'Odd.');
    }

    // ---- My request --------------------------------------------------------

    public function test_my_pending_request_and_cancelling_it(): void
    {
        $request = $this->leave($this->staff, '2026-10-20', '2026-10-22', ['reason' => "Family trip\nBack on Friday"]);
        $this->actingAs($this->user, 'web')->get('/app/leave/requests/'.$request->public_id)->assertOk()
            ->assertSee('Vacation leave')->assertSee('Paid')->assertSee('Pending')
            ->assertSee('3 calendar days (weekends included)')->assertSee('Tue 20 Oct – Thu 22 Oct 2026')
            ->assertSee('Family trip')->assertSee('Cancel request')
            ->assertDontSee('Requested by')->assertDontSee('Approve');

        Livewire::test(LeaveRequestShow::class, ['publicId' => $request->public_id])
            ->call('ask', 'approve')->assertSet('confirming', null) // not mine to approve
            ->call('ask', 'cancel')
            ->assertSee('Cancel this request?')->assertSee('Your manager will no longer see it as pending.')
            ->assertSee('Reason (optional)')->assertSee('Keep it')
            ->call('dismiss')->assertSet('confirming', null)
            ->call('ask', 'cancel')->set('text', 'Plans changed')
            ->call('confirm')
            ->assertSee('Request cancelled.')
            ->assertSee('Cancelled')
            ->assertDontSee('Cancel request');

        $this->assertSame(LeaveRequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_cancel_is_offered_for_approved_leave_only_before_it_starts(): void
    {
        $future = $this->leave($this->staff, '2026-10-20', '2026-10-20', ['status' => LeaveRequestStatus::Approved]);
        $started = $this->leave($this->staff, '2026-10-13', '2026-10-14', ['status' => LeaveRequestStatus::Approved]);
        $rejected = $this->leave($this->staff, '2026-11-02', '2026-11-02', ['status' => LeaveRequestStatus::Rejected]);
        $this->actingAs($this->user, 'web');

        Livewire::test(LeaveRequestShow::class, ['publicId' => $future->public_id])
            ->assertSee('Cancel request')
            ->call('ask', 'cancel')->assertSee('Your approved leave will be cancelled.');
        Livewire::test(LeaveRequestShow::class, ['publicId' => $started->public_id])->assertDontSee('Cancel request');
        Livewire::test(LeaveRequestShow::class, ['publicId' => $rejected->public_id])->assertDontSee('Cancel request');
    }

    public function test_someone_elses_request_is_not_available(): void
    {
        $other = $this->leave(Staff::factory()->create(), '2026-10-20', '2026-10-20');

        $this->actingAs($this->user, 'web')->get('/app/leave/requests/'.$other->public_id)->assertOk()
            ->assertSee('This request is no longer available.')
            ->assertDontSee($other->reason);
    }

    // ---- Team requests -----------------------------------------------------

    public function test_team_requests_filter_and_list_my_reports_only(): void
    {
        $manager = $this->manager();
        $this->leave($this->staff, '2026-10-27', '2026-10-27', ['reason' => 'Later trip']);
        $this->leave($this->staff, '2026-10-20', '2026-10-21', ['reason' => 'Sooner trip']);
        $this->leave($this->staff, '2026-09-01', '2026-09-01', ['status' => LeaveRequestStatus::Approved]);
        $this->leave(Staff::factory()->create(['first_name' => 'Zed', 'preferred_name' => null]), '2026-10-20', '2026-10-20');
        $this->actingAs($manager, 'web');

        $this->get('/app/leave/team')->assertOk()
            ->assertSee('Pending (2)')
            ->assertSeeInOrder(['Tue 20 Oct – Wed 21 Oct 2026', 'Tue 27 Oct 2026'])
            ->assertSee($this->staff->fresh()->display_name)
            ->assertDontSee('Zed');

        Livewire::test(TeamLeave::class)
            ->call('show', 'approved')->assertSet('status', 'approved')
            ->assertSee('Tue 1 Sep 2026')->assertDontSee('Tue 27 Oct 2026')
            ->call('show', 'rejected')->assertSee('No requests here.')
            ->call('show', 'all')->assertSee('Tue 1 Sep 2026')->assertSee('Tue 27 Oct 2026')
            ->call('show', 'nonsense')->assertSet('status', 'pending');
    }

    public function test_someone_who_reviews_nobody_sees_nothing_waiting(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/leave/team')->assertOk()
            ->assertSee('No requests are waiting for you.')
            ->assertDontSee('Pending (');
    }

    public function test_a_manager_approves_with_a_note_and_the_history_shows_it(): void
    {
        $manager = $this->manager();
        $request = $this->leave($this->staff, '2026-10-20', '2026-10-20');
        $this->actingAs($manager, 'web');

        $this->get('/app/leave/team/'.$request->public_id)->assertOk()
            ->assertSee('Requested by')->assertSee($this->staff->fresh()->display_name)
            ->assertSee('Approve')->assertSee('Reject')->assertDontSee('Cancel request');

        Livewire::test(LeaveRequestShow::class, ['publicId' => $request->public_id, 'viewer' => 'team'])
            ->call('ask', 'cancel')->assertSet('confirming', null) // not theirs to cancel
            ->call('ask', 'approve')
            ->assertSee('Approve this request?')->assertSee('Note (optional)')
            ->set('text', 'Enjoy the trip')
            ->call('confirm')
            ->assertSee('Request approved.')
            ->assertSee('Approved')
            ->assertSeeInOrder(['History', 'Approved by', 'Today 10:00', 'Enjoy the trip'])
            ->assertDontSee('Reject this request?');

        $this->assertSame(LeaveRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_reject_needs_a_reason(): void
    {
        $manager = $this->manager();
        $request = $this->leave($this->staff, '2026-10-20', '2026-10-20');
        $this->actingAs($manager, 'web');

        Livewire::test(LeaveRequestShow::class, ['publicId' => $request->public_id, 'viewer' => 'team'])
            ->call('ask', 'reject')
            ->assertSee('Reject this request?')
            ->set('text', ' no ')
            ->call('confirm')
            ->assertHasErrors(['text'])
            ->assertSee('Give a reason (at least 3 characters).')
            ->set('text', 'Peak week')
            ->call('confirm')
            ->assertSee('Request rejected.')
            ->assertSee('Peak week');

        $this->assertSame(LeaveRequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_a_decided_request_offers_no_actions_and_a_stale_decision_is_refused(): void
    {
        $manager = $this->manager();
        $request = $this->leave($this->staff, '2026-10-20', '2026-10-20');
        $this->actingAs($manager, 'web');

        $page = Livewire::test(LeaveRequestShow::class, ['publicId' => $request->public_id, 'viewer' => 'team'])
            ->call('ask', 'approve');
        $request->update(['status' => LeaveRequestStatus::Cancelled]); // Lia cancelled meanwhile
        $page->call('confirm')
            ->assertSet('actionMessage', fn ($message) => is_string($message) && $message !== '')
            ->assertSee('Cancelled')
            ->assertDontSee('>Approve<', false);

        $this->assertSame(LeaveRequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_a_colleague_cannot_open_or_decide_a_team_request(): void
    {
        $request = $this->leave(Staff::factory()->create(), '2026-10-20', '2026-10-20');

        $this->actingAs($this->user, 'web')->get('/app/leave/team/'.$request->public_id)->assertOk()
            ->assertSee("You don't have access to this request.")
            ->assertDontSee($request->reason);

        Livewire::test(LeaveRequestShow::class, ['publicId' => $request->public_id, 'viewer' => 'team'])
            ->call('ask', 'approve')->call('confirm');

        $this->assertSame(LeaveRequestStatus::Pending, $request->fresh()->status);
    }

    // ---- My status ---------------------------------------------------------

    public function test_my_status_shows_the_current_status_history_and_check_ins(): void
    {
        StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => OperationalStatus::InField, 'created_at' => '2026-10-12 10:40:00']);
        StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => OperationalStatus::Available, 'created_at' => '2026-10-13 01:12:00']);
        StaffCheckIn::factory()->create(['staff_id' => $this->staff->id, 'latitude' => 14.5547123, 'longitude' => 121.0244456, 'accuracy_meters' => 12, 'location_label' => 'Client site', 'note' => 'Gate B', 'created_at' => '2026-10-13 00:30:00']);
        StaffCheckIn::factory()->create(['staff_id' => $this->staff->id, 'location_label' => null, 'accuracy_meters' => null, 'created_at' => '2026-10-01 00:30:00']);
        StaffOperationalStatus::factory()->create(['status' => OperationalStatus::Busy]); // someone else

        $this->actingAs($this->user, 'web')->get('/app/status')->assertOk()
            ->assertSeeInOrder(['Current status', 'Available', 'Set Today 09:12', 'Change status', 'Available', 'Busy', 'In a meeting', 'In the field', 'Off duty'])
            ->assertSee("Off duty: not working right now. This isn't leave.", false)
            ->assertSeeInOrder(['Recent status changes', 'Available', 'Today 09:12', 'In the field', 'Yesterday 18:40', 'All status changes'])
            ->assertSeeInOrder(['Recent check-ins', 'Client site', 'Today 08:30 · ±12 m', 'Gate B', '14.55471, 121.02445', 'No place name', 'Thu 1 Oct 08:30', 'All check-ins'])
            ->assertSee(route('portal.status.changes'), false)
            ->assertSee(route('portal.status.check-ins'), false)
            ->assertDontSee('Busy</span>', false)
            ->assertDontSee('>Check in<', false);
    }

    public function test_my_status_empty_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/status')
            ->assertSee('No status set yet')
            ->assertSee("You haven't set a status yet.", false)
            ->assertSee("You haven't checked in yet.", false)
            ->assertDontSee('All status changes');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/status')
            ->assertSee('No staff profile is linked to this account.')
            ->assertDontSee('Change status');
    }

    public function test_changing_my_status(): void
    {
        $this->actingAs($this->user, 'web');

        Livewire::test(MyStatus::class)
            ->call('setStatus', 'in_meeting')
            ->assertSee('Status set to In a meeting.')
            ->assertSeeInOrder(['Current status', 'In a meeting', 'Set Today 10:00'])
            ->call('setStatus', 'on_holiday'); // not a status; ignored

        $this->assertSame(1, StaffOperationalStatus::query()->where('staff_id', $this->staff->id)->count());
        $this->assertSame(OperationalStatus::InMeeting, StaffOperationalStatus::query()->sole()->status);
    }

    public function test_the_history_pages_page_by_twenty(): void
    {
        foreach (range(1, 21) as $i) {
            StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => OperationalStatus::Busy, 'created_at' => Carbon::parse('2026-10-01 00:00:00')->addMinutes($i)]);
        }
        StaffCheckIn::factory()->create(['staff_id' => $this->staff->id, 'location_label' => 'Warehouse']);
        $this->actingAs($this->user, 'web');

        $this->get('/app/status/changes')->assertOk()->assertSee('All status changes')->assertSee('Page 1 of 2');
        Livewire::test(StatusHistory::class)->call('goToPage', 2)->assertSee('Page 2 of 2');

        $this->get('/app/status/check-ins')->assertOk()->assertSee('All check-ins')->assertSee('Warehouse');
        Livewire::test(StatusHistory::class, ['kind' => 'check-ins'])->assertSee('Warehouse');
        Livewire::test(StatusHistory::class, ['kind' => 'other'])->assertSet('kind', 'changes');
    }

    public function test_an_ended_session_goes_to_login(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                throw new PortalApiException(401, 'Unauthenticated.');
            }
        });
        $this->actingAs($this->user, 'web');

        foreach ([Leave::class, TeamLeave::class, MyStatus::class] as $component) {
            Livewire::test($component)->assertRedirect(route('login'));
        }
    }
}
