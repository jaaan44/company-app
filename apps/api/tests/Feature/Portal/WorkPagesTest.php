<?php

namespace Tests\Feature\Portal;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Livewire\Portal\Clients;
use App\Livewire\Portal\Projects;
use App\Livewire\Portal\Schedule;
use App\Livewire\Portal\Tasks;
use App\Livewire\Portal\TaskShow;
use App\Livewire\Portal\WorkLogForm;
use App\Models\Client;
use App\Models\Contact;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff Web Portal — Work (Phase 34B): Tasks, Work logs, Projects,
 * Clients and the Schedule, against the real API, showing what the
 * mobile screens show. "Today" is Tue 13 Oct 2026 in Manila.
 */
class WorkPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/app/tasks', '/app/work-logs', '/app/work-logs/new', '/app/projects', '/app/clients', '/app/schedule'];

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-13 02:00:00', 'UTC')); // Tue 13 Oct, 10:00 Manila

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Lia', 'preferred_name' => null]);
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->create(['assignee_staff_id' => $this->staff->id] + $attributes);
    }

    private function memberProject(array $attributes = [], string $role = 'member'): Project
    {
        $project = Project::factory()->create($attributes + ['status' => ProjectStatus::Active]);
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id, 'role' => $role]);

        return $project;
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

    public function test_tasks_and_schedule_are_tabs_and_the_other_work_pages_are_on_more(): void
    {
        foreach (['/app/tasks' => '/app/tasks', '/app/schedule' => '/app/schedule'] as $url => $tab) {
            $page = $this->actingAs($this->user, 'web')->get($url)->assertDontSee('Back to More')->getContent();
            $this->assertMatchesRegularExpression('#href="[^"]*'.$tab.'"\s+aria-current="page"#', $page);
        }
        $this->get('/app/more')->assertSeeInOrder(['My work logs', 'Projects', 'Clients']);
    }

    // ---- Tasks -------------------------------------------------------------

    public function test_tasks_list_open_with_due_labels_and_done(): void
    {
        $project = Project::factory()->create(['name' => 'Plant 2 retrofit']);
        $this->task(['title' => 'Overdue job', 'due_date' => '2026-10-01', 'project_id' => $project->id]);
        $this->task(['title' => 'Today job', 'due_date' => '2026-10-13', 'status' => TaskStatus::InProgress]);
        $this->task(['title' => 'Later job', 'due_date' => '2026-11-02']);
        $this->task(['title' => 'Finished job', 'status' => TaskStatus::Completed, 'completed_at' => now()]);
        Task::factory()->create(['title' => 'Someone else’s job']);

        $this->actingAs($this->user, 'web')->get('/app/tasks')->assertOk()
            ->assertSee('Overdue job')->assertSee('Plant 2 retrofit')->assertSee('Overdue · 1 Oct')
            ->assertSee('Today job')->assertSee('Due today')->assertSee('In progress')
            ->assertSee('Later job')->assertSee('Due 2 Nov')
            ->assertDontSee('Finished job')->assertDontSee('Someone else');

        Livewire::test(Tasks::class)->call('showState', 'closed')
            ->assertSee('Finished job')->assertDontSee('Later job')->assertSet('state', 'closed');
    }

    public function test_tasks_empty_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web');
        Livewire::test(Tasks::class)->assertSee('No open tasks assigned to you.')
            ->call('showState', 'closed')->assertSee('No completed tasks yet.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/tasks')
            ->assertSee('No staff profile is linked to this account.');
    }

    public function test_task_detail_and_a_confirmed_status_change(): void
    {
        $project = Project::factory()->create(['name' => 'Plant 2 retrofit']);
        $task = $this->task(['title' => 'Replace valve', 'due_date' => '2026-10-13', 'description' => "Line 1\nLine 2", 'project_id' => $project->id]);

        $this->actingAs($this->user, 'web')->get('/app/tasks/'.$task->public_id)->assertOk()
            ->assertSee('Replace valve')->assertSee('Priority: Normal')
            ->assertSeeInOrder(['Status', 'To do', 'In progress', 'Blocked', 'Completed'])
            ->assertSee('13 Oct 2026 · Due today')
            ->assertSee(route('portal.project', $project->public_id), false)
            ->assertSee('Log work')->assertSee('Line 1');

        Livewire::test(TaskShow::class, ['publicId' => $task->public_id])
            ->call('setStatus', 'in_progress')
            ->assertSee('Status changed to In progress.')
            ->call('setStatus', 'cancelled'); // never offered; ignored

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_a_cancelled_task_is_read_only_and_an_independent_task_says_so(): void
    {
        $task = $this->task(['status' => TaskStatus::Cancelled, 'description' => null]);

        $this->actingAs($this->user, 'web')->get('/app/tasks/'.$task->public_id)
            ->assertSee("This task was cancelled. Its status can't be changed here.", false)
            ->assertSee('Independent task')->assertSee('No description.')
            ->assertDontSee('Log work');
    }

    public function test_a_refused_status_change_shows_the_reason_and_locks(): void
    {
        $task = $this->task();
        $this->actingAs($this->user, 'web');
        $component = Livewire::test(TaskShow::class, ['publicId' => $task->public_id]);

        $task->update(['assignee_staff_id' => Staff::factory()->create()->id]); // reassigned meanwhile

        $component->call('setStatus', 'blocked')->assertSet('locked', true);
        $this->assertNotNull($component->get('saveMessage'));
        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
    }

    public function test_a_task_i_cannot_see_and_an_unknown_task(): void
    {
        $other = Task::factory()->create();

        $this->actingAs($this->user, 'web')->get('/app/tasks/'.$other->public_id)->assertSee("You don't have access to this task.");
        $this->get('/app/tasks/01NOTAREALTASK000000000000')->assertSee('This task is no longer available.');
    }

    // ---- Work logs ---------------------------------------------------------

    public function test_work_logs_are_grouped_by_day_with_durations(): void
    {
        $project = $this->memberProject(['name' => 'Plant 2 retrofit']);
        $task = $this->task(['title' => 'Replace valve', 'project_id' => $project->id]);
        WorkLog::factory()->create(['staff_id' => $this->staff->id, 'task_id' => $task->id, 'project_id' => $project->id, 'work_date' => '2026-10-13', 'duration_minutes' => 90, 'description' => 'Fitted the valve']);
        WorkLog::factory()->create(['staff_id' => $this->staff->id, 'project_id' => $project->id, 'work_date' => '2026-10-12', 'duration_minutes' => 45, 'description' => 'Survey']);
        WorkLog::factory()->create(['staff_id' => $this->staff->id, 'project_id' => $project->id, 'work_date' => '2026-10-07', 'duration_minutes' => 120, 'description' => 'Planning']);
        WorkLog::factory()->create(['description' => 'Not mine']);

        $this->actingAs($this->user, 'web')->get('/app/work-logs')->assertOk()
            ->assertSeeInOrder(['Today', 'Replace valve', 'Plant 2 retrofit', 'Fitted the valve', '1 h 30 min', 'Yesterday', 'Survey', '45 min', 'Wed 7 Oct', 'Planning', '2 h'])
            ->assertDontSee('Not mine');
    }

    public function test_log_work_on_a_task_then_edit_and_delete_it(): void
    {
        $project = $this->memberProject(['name' => 'Plant 2 retrofit']);
        $task = $this->task(['title' => 'Replace valve', 'project_id' => $project->id]);
        $this->actingAs($this->user, 'web');

        Livewire::test(WorkLogForm::class, ['task' => $task->public_id])
            ->assertSee('Replace valve')->assertSet('workDate', '2026-10-13')
            ->set('hours', '1')->set('minutes', '15')->set('description', 'Fitted the valve')
            ->call('save')
            ->assertRedirect(route('portal.work-logs'));
        $this->assertSame('Work logged.', session('status'));

        $log = WorkLog::query()->sole();
        $this->assertSame([$task->id, 75, 'Fitted the valve'], [$log->task_id, $log->duration_minutes, $log->description]);

        Livewire::test(WorkLogForm::class, ['publicId' => $log->public_id])
            ->assertSee('Edit work log')->assertSee('Replace valve')
            ->assertSet('hours', '1')->assertSet('minutes', '15')
            ->set('workDate', '2026-10-12')->set('minutes', '30')
            ->call('save')->assertRedirect(route('portal.work-logs'));
        $this->assertSame(['2026-10-12', 90], [$log->fresh()->work_date->toDateString(), $log->fresh()->duration_minutes]);

        Livewire::test(WorkLogForm::class, ['publicId' => $log->public_id])
            ->call('delete')->assertRedirect(route('portal.work-logs'));
        $this->assertSame('Work log deleted.', session('status'));
        $this->assertSame(0, WorkLog::query()->count());
    }

    public function test_log_work_on_a_project_from_the_picker(): void
    {
        $project = $this->memberProject(['name' => 'Plant 2 retrofit']);
        $this->memberProject(['name' => 'Old closed job', 'status' => ProjectStatus::Completed]);
        Project::factory()->create(['name' => 'Not my project']);
        $this->task(['title' => 'Open task of mine']);
        $this->actingAs($this->user, 'web');

        Livewire::test(WorkLogForm::class)
            ->assertSee('My open tasks')->assertSee('Open task of mine')
            ->assertSee('My projects')->assertSee('Plant 2 retrofit')
            ->assertDontSee('Old closed job')->assertDontSee('Not my project')
            ->set('target', 'project:'.$project->public_id)->set('hours', '2')->set('description', 'Survey')
            ->call('save')->assertRedirect(route('portal.work-logs'));

        $this->assertSame($project->id, WorkLog::query()->sole()->project_id);
    }

    public function test_the_form_checks_like_the_mobile_form(): void
    {
        $this->actingAs($this->user, 'web');

        Livewire::test(WorkLogForm::class)->call('save')
            ->assertHasErrors(['target', 'duration', 'description'])
            ->assertSee('Choose what this work was for.')->assertSee('Enter how long you worked.')->assertSee('Describe the work.')
            ->set('workDate', '2026-10-14')->set('hours', '25')->call('save')
            ->assertSee('The work date cannot be later than today.')->assertSee('A work log can be at most 24 hours.')
            ->set('workDate', '2025-10-12')->set('hours', '1')->set('minutes', '75')->call('save')
            ->assertSee('Choose a date within the last year.')->assertSee('Enter hours and minutes.');
        $this->assertSame(0, WorkLog::query()->count());
    }

    public function test_server_validation_lands_on_the_fields(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'POST') {
                    throw new PortalApiException(422, 'Invalid.', ['work_date' => ['The work date cannot be later than today.'], 'project_id' => ['You are not a member of this project.'], 'staff_id' => ['Odd.']]);
                }

                return match (true) {
                    str_starts_with($path, '/me/work-logs') => ['data' => [], 'meta' => ['company_day' => ['date' => '2026-10-13'], 'last_page' => 1]],
                    default => ['data' => ['tasks' => [], 'staff' => null]],
                };
            }
        });
        $this->actingAs($this->user, 'web');

        Livewire::test(WorkLogForm::class)
            ->set('target', 'project:01ABCDEFGHJKMNPQRSTVWXYZ00')->set('hours', '1')->set('description', 'x')
            ->call('save')
            ->assertHasErrors(['workDate', 'target'])
            ->assertSee('You are not a member of this project.')
            ->assertSet('generalError', 'Odd.');
    }

    public function test_an_unknown_work_log_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/work-logs/01NOTAREALLOG0000000000000/edit')
            ->assertSee('This work log is no longer available.');
        WorkLog::factory()->create();
        $other = WorkLog::query()->sole();
        $this->get('/app/work-logs/'.$other->public_id.'/edit')->assertSee('This work log is no longer available.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web');
        $this->get('/app/work-logs')->assertSee('No staff profile is linked to this account.')->assertDontSee('>Log work<', false);
        $this->get('/app/work-logs/new')->assertSee('No staff profile is linked to this account.');
    }

    // ---- Projects and Clients ---------------------------------------------

    public function test_projects_are_mine_only_and_searchable(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Foods']);
        $this->memberProject(['name' => 'Plant 2 retrofit', 'project_code' => 'P2R', 'client_id' => $client->id], 'project_lead');
        $this->memberProject(['name' => 'Cold room', 'project_code' => 'CR1']);
        Project::factory()->create(['name' => 'Not my project']);

        $this->actingAs($this->user, 'web')->get('/app/projects')->assertOk()
            ->assertSee('Plant 2 retrofit')->assertSee('P2R · Acme Foods')->assertSee('Project lead')->assertSee('Active')
            ->assertSee('Cold room')->assertDontSee('Not my project');

        Livewire::test(Projects::class)->set('search', 'cr1')->assertSee('Cold room')->assertDontSee('Plant 2 retrofit')
            ->set('search', 'zzz')->assertSee('No project matches "zzz".');

        // A manager also sees only their own projects here (mobile R-20).
        $this->app['auth']->forgetGuards();
        $manager = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $manager->id]);
        $this->actingAs($manager, 'web')->get('/app/projects')->assertSee("You aren't a member of any projects yet.");
    }

    public function test_a_project_page(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Foods']);
        $project = $this->memberProject(['name' => 'Plant 2 retrofit', 'client_id' => $client->id, 'start_date' => '2026-09-01', 'target_end_date' => '2026-12-18', 'description' => 'Retrofit both lines.']);
        $ben = Staff::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz', 'preferred_name' => null]);
        ProjectMembership::factory()->projectLead()->create(['project_id' => $project->id, 'staff_id' => $ben->id]);
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'title' => 'Line 1 done', 'due_date' => '2026-11-02']);

        $this->actingAs($this->user, 'web')->get('/app/projects/'.$project->public_id)->assertOk()
            ->assertSee('Plant 2 retrofit')->assertSee(route('portal.client', $client->public_id), false)
            ->assertSeeInOrder(['My role', 'Member', 'Start date', '1 Sep 2026', 'Target end date', '18 Dec 2026'])
            ->assertSee('Retrofit both lines.')
            ->assertSee('Members (2)')->assertSee('Ben Cruz')->assertSee('Project lead')->assertSee(route('portal.staff', $ben->public_id), false)
            ->assertSeeInOrder(['Milestones', 'Line 1 done', '2 Nov 2026', 'Pending']);

        $notMine = Project::factory()->create();
        $this->get('/app/projects/'.$notMine->public_id)->assertSee("You don't have access to this project.");
        $this->get('/app/projects/01NOTAREALPROJECT000000000')->assertSee('This project no longer exists.');
    }

    public function test_clients_list_search_and_page(): void
    {
        $acme = Client::factory()->create(['name' => 'Acme Foods', 'client_code' => 'ACME', 'email' => 'ops@acme.test', 'phone' => '+63 2 555 0100', 'website' => 'acme.test', 'address_line1' => '1 Dock Rd', 'city' => 'Pasig', 'state_province' => null, 'postal_code' => '1600', 'country' => 'PH']);
        Client::factory()->create(['name' => 'Beta Corp']);
        Client::factory()->inactive()->create(['name' => 'Gone Ltd']);
        Contact::factory()->primary()->create(['client_id' => $acme->id, 'first_name' => 'Rosa', 'last_name' => 'Lim', 'job_title' => 'Plant manager', 'email' => 'rosa@acme.test']);
        Contact::factory()->inactive()->create(['client_id' => $acme->id, 'first_name' => 'Old', 'last_name' => 'Contact']);

        $this->actingAs($this->user, 'web')->get('/app/clients')->assertOk()
            ->assertSee('Acme Foods')->assertSee('ACME')->assertSee('Beta Corp')->assertDontSee('Gone Ltd');
        Livewire::test(Clients::class)->set('search', 'beta')->assertSee('Beta Corp')->assertDontSee('Acme Foods');

        $this->get('/app/clients/'.$acme->public_id)->assertOk()
            ->assertSeeInOrder(['Email', 'ops@acme.test', 'Copy email', 'Phone', '+63 2 555 0100', 'Website', 'acme.test', 'Address', '1 Dock Rd, Pasig, 1600, PH', 'Copy address'])
            ->assertSee('Contacts (1)')->assertSee('Rosa Lim')->assertSee('Primary')->assertSee('Plant manager')->assertSee('rosa@acme.test')
            ->assertDontSee('Old Contact');
        $this->get('/app/clients/01NOTAREALCLIENT0000000000')->assertSee('This client no longer exists.');
    }

    // ---- Schedule ----------------------------------------------------------

    public function test_the_schedule_week_from_the_company_today(): void
    {
        $entry = ScheduleEntry::factory()->create([
            'creator_staff_id' => $this->staff->id, 'title' => 'Site survey',
            'starts_at' => Carbon::parse('2026-10-14 01:00:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-14 02:30:00', 'UTC'),
        ]);
        $task = $this->task(['title' => 'Replace valve', 'due_date' => '2026-10-13']);
        LeaveRequest::factory()->approved()->create(['staff_id' => $this->staff->id, 'start_date' => '2026-10-16', 'end_date' => '2026-10-16', 'total_days' => 1]);

        $this->actingAs($this->user, 'web')->get('/app/schedule')->assertOk()
            ->assertSee('13 – 19 Oct')
            ->assertSeeInOrder(['Today', 'Replace valve', 'Task · To do · All day', 'Tomorrow', 'Site survey', 'Meeting · 09:00 – 10:30', 'Fri 16 Oct', 'Leave · All day', 'Sat 17 Oct', 'Nothing scheduled.'])
            ->assertSee(route('portal.task', $task->public_id), false)
            ->assertSee(route('portal.schedule-entry', $entry->public_id), false);

        Livewire::test(Schedule::class)->assertSet('from', '')->assertSet('shownFrom', '2026-10-13')
            ->call('move', 7)->assertSet('from', '2026-10-20')->assertSee('20 – 26 Oct')->assertSee('Today')
            ->call('move', -14)->assertSet('from', '2026-10-06')->assertSee('6 – 12 Oct')
            ->call('today')->assertSet('from', '')->assertSee('13 – 19 Oct');
    }

    public function test_a_schedule_entry_page_and_no_profile(): void
    {
        $ben = Staff::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz', 'preferred_name' => null]);
        $entry = ScheduleEntry::factory()->create([
            'creator_staff_id' => $ben->id, 'title' => 'Site survey', 'description' => 'Bring the meter.',
            'starts_at' => Carbon::parse('2026-10-14 01:00:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-14 02:30:00', 'UTC'),
        ]);
        $entry->participants()->attach($this->staff->id);

        $this->actingAs($this->user, 'web')->get('/app/schedule/entries/'.$entry->public_id)->assertOk()
            ->assertSee('Site survey')->assertSee('Meeting')
            ->assertSeeInOrder(['When', 'Wed 14 Oct · 09:00 – 10:30', 'Organizer', 'Ben Cruz', 'People (1)', 'Lia', 'Description', 'Bring the meter.']);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/schedule')->assertSee('No staff profile is linked to this account.');
    }

    // ---- Home links ---------------------------------------------------------

    public function test_home_links_to_tasks_and_today_items(): void
    {
        $task = $this->task(['title' => 'Replace valve', 'due_date' => '2026-10-13']);
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $this->staff->id, 'starts_at' => Carbon::parse('2026-10-13 03:00:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-13 04:00:00', 'UTC')]);

        $this->actingAs($this->user, 'web')->get('/app')
            // The My work card itself links (the navigation also links to Tasks).
            ->assertSee('<a href="'.route('portal.tasks').'" class="chevron card', false)
            ->assertSee(route('portal.task', $task->public_id), false)
            ->assertSee(route('portal.schedule-entry', $entry->public_id), false);
    }
}
