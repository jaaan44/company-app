<?php

namespace Tests\Feature\Portal;

use App\Enums\StaffStatus;
use App\Livewire\Portal\Directory;
use App\Livewire\Portal\Home;
use App\Livewire\Portal\Profile;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\StaffOperationalStatus;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff Web Portal pages (Phase 34A): who may open them, and that Home,
 * My profile and the Staff directory show what the mobile screens show,
 * from the same API.
 */
class PortalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/app', '/app/more', '/app/profile', '/app/staff'];

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-13 02:00:00', 'UTC')); // 10:00 Manila

        $this->user = User::factory()->staff()->create(['email' => 'lia@company-app.test']);
        $manager = Staff::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz', 'preferred_name' => null]);
        $this->staff = Staff::factory()->create([
            'user_id' => $this->user->id,
            'first_name' => 'Amelia',
            'last_name' => 'Ramos',
            'preferred_name' => 'Lia',
            'employee_number' => 'EMP-0042',
            'company_email' => 'lia.ramos@company.test',
            'company_phone' => '+63 900 000 0042',
            'hire_date' => '2024-03-01',
            'manager_id' => $manager->id,
            'position_id' => Position::factory()->create(['title' => 'Field Technician'])->id,
            'department_id' => Department::factory()->create(['name' => 'Operations'])->id,
            'team_id' => Team::factory()->create(['name' => 'North Crew'])->id,
        ]);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertRedirect('/login');
        }
        $this->get('/app/staff/'.$this->staff->public_id)->assertRedirect('/login');
    }

    public function test_every_active_role_can_open_the_portal(): void
    {
        foreach ([User::factory()->staff()->create(), User::factory()->manager()->create(), User::factory()->administrator()->create(), User::factory()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            foreach (self::PAGES as $page) {
                $this->actingAs($user, 'web')->get($page)->assertOk();
            }
        }
    }

    public function test_a_suspended_account_is_signed_out(): void
    {
        $user = User::factory()->staff()->suspended()->create();

        $this->actingAs($user, 'web')->get('/app')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_only_administrators_see_the_admin_link(): void
    {
        $this->actingAs($this->user, 'web')->get('/app')->assertDontSee(' Admin</a>', false);
        $this->app['auth']->forgetGuards(); // a new request in production starts with fresh guards
        $this->actingAs(User::factory()->administrator()->create(), 'web')->get('/app')->assertSee(' Admin</a>', false);
    }

    public function test_home_shows_greeting_today_counts_and_announcements(): void
    {
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $this->staff->id,
            'title' => 'Site survey',
            'starts_at' => Carbon::parse('2026-10-13 01:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-13 02:30:00', 'UTC'),
        ]);
        Announcement::factory()->published()->create(['title' => 'Office closed Friday']);

        $this->actingAs($this->user, 'web')->get('/app')
            ->assertOk()
            ->assertSeeInOrder(['Hello, Lia', 'Field Technician · Operations', 'North Crew'])
            ->assertSee('Today · Tue 13 Oct')
            ->assertSeeInOrder(['Site survey', 'Meeting · 09:00 – 10:30'])
            // 37D: My work (the tasks card) comes before Needs attention.
            ->assertSeeInOrder(['My work', 'My tasks', '0 overdue', 'Needs attention', 'No unread messages', 'No unread notifications'])
            ->assertSeeInOrder(['Latest announcements', 'Office closed Friday'])
            // Mobile redesign: the hero has the initials (a link to My profile) and takes the app bar's place on phones.
            ->assertSee('aria-label="My profile"', false)
            ->assertSee('>L</a>', false) // her preferred name
            ->assertSee('max-md:hidden">', false);
    }

    public function test_home_quick_actions_link_to_existing_pages_by_permission(): void
    {
        // Staff: no tasks.create, so no New task; the others are links to pages they already have.
        $this->actingAs($this->user, 'web')->get('/app')
            ->assertOk()
            ->assertSee('aria-label="Quick actions"', false)
            ->assertSeeInOrder([route('portal.messages.new'), 'Message', route('portal.leave.new'), 'Request leave', route('portal.status'), 'My status'], false)
            ->assertDontSee(route('portal.tasks.new'), false);

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin, 'web')->get('/app')
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Quick actions"', route('portal.tasks.new'), 'New task'], false);
    }

    public function test_home_shows_the_first_four_employees_as_cards(): void
    {
        // Lia Ramos and Ben Cruz (setUp) plus five more: A–Z by last name, the first four show.
        foreach ([['Anna', 'Abad'], ['Bo', 'Bautista'], ['Cy', 'Castro'], ['Di', 'Diaz'], ['Zed', 'Zamora']] as [$first, $last]) {
            Staff::factory()->create(['first_name' => $first, 'last_name' => $last, 'preferred_name' => null]);
        }
        $anna = Staff::query()->where('first_name', 'Anna')->firstOrFail();
        StaffOperationalStatus::factory()->create(['staff_id' => $anna->id, 'status' => 'in_meeting']);
        Staff::factory()->create(['first_name' => 'Gone', 'last_name' => 'Aaron', 'preferred_name' => null, 'status' => StaffStatus::Separated]);

        $this->actingAs($this->user, 'web')->get('/app')
            ->assertOk()
            ->assertSeeInOrder(['Employees', 'View all (7)', 'In a meeting', 'Anna Abad', 'Bo Bautista', 'Cy Castro', 'Ben Cruz', 'Today']) // compact cards: the status above the name, as in the reference
            ->assertSee(route('portal.staff', $anna->public_id), false)
            ->assertDontSee('Di Diaz')
            ->assertDontSee('Gone Aaron');
    }

    public function test_home_cards_show_leave_as_a_short_range(): void
    {
        $anna = Staff::factory()->create(['first_name' => 'Anna', 'last_name' => 'Abad', 'preferred_name' => null]);
        LeaveRequest::factory()->approved()->create(['staff_id' => $anna->id, 'start_date' => '2026-10-13', 'end_date' => '2026-10-15']);

        $this->actingAs($this->user, 'web')->get('/app')
            ->assertOk()
            ->assertSeeInOrder(['Employees', 'On leave', '13 – 15 Oct', 'Anna Abad']);
    }

    public function test_home_has_no_employees_without_the_directory(): void
    {
        $role = Role::query()->create(['name' => 'no-directory', 'label' => 'No directory']);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'web')->get('/app')
            ->assertOk()
            ->assertDontSee('aria-labelledby="employees"', false);
    }

    public function test_home_without_a_staff_profile(): void
    {
        $this->actingAs(User::factory()->staff()->create(['name' => 'Pat Admin']), 'web')->get('/app')
            ->assertOk()
            ->assertSee('Hello, Pat Admin')
            ->assertSee("Your account isn't linked to an employee profile", false)
            ->assertDontSee('My tasks')
            ->assertSee('No unread notifications');
    }

    public function test_profile_shows_work_employment_and_account(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/profile')
            ->assertOk()
            ->assertSeeInOrder(['Lia', 'Amelia Ramos', 'Field Technician · Operations', 'North Crew'])
            ->assertSeeInOrder(['Manager', 'Ben Cruz', 'Company email', 'lia.ramos@company.test', 'Copy email', 'Company phone', '+63 900 000 0042', 'Copy phone'])
            ->assertSeeInOrder(['Employee number', 'EMP-0042', 'Hire date', '1 Mar 2024'])
            ->assertSeeInOrder(['Login email', 'lia@company-app.test', 'Role', 'Staff'])
            ->assertSee('To change your email or role, contact an administrator.');
    }

    public function test_profile_without_a_staff_profile(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/profile')
            ->assertOk()
            ->assertSee('No staff profile is linked to this account.')
            ->assertSee('Login email')
            ->assertDontSee('Employee number');
    }

    public function test_api_data_is_not_kept_in_the_page_snapshot(): void
    {
        $html = $this->actingAs($this->user, 'web')->get('/app/profile')->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', (string) $html, $snapshots);
        $this->assertNotEmpty($snapshots[1]);
        foreach ($snapshots[1] as $snapshot) {
            $this->assertStringNotContainsString('EMP-0042', html_entity_decode($snapshot));
            $this->assertStringNotContainsString('lia.ramos@company.test', html_entity_decode($snapshot));
        }
    }

    public function test_directory_lists_active_staff_and_searches_by_name(): void
    {
        Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]);
        Staff::factory()->create(['first_name' => 'Gone', 'last_name' => 'Person', 'status' => StaffStatus::Separated]);

        $this->actingAs($this->user, 'web')->get('/app/staff')
            ->assertOk()
            ->assertSee('Lia')
            ->assertSee('Kai')
            ->assertSee('Field Technician · Operations')
            ->assertDontSee('Gone');

        $this->actingAs($this->user, 'web');
        Livewire::test(Directory::class)
            ->set('search', 'kai')
            ->assertSee('Kai')
            ->assertDontSee('Amelia')
            ->set('search', 'nobody-here')
            ->assertSee('No one matches "nobody-here".')
            ->call('clearSearch')
            ->assertSet('search', '')
            ->assertSee('Kai');
    }

    public function test_directory_shows_each_persons_status_to_those_who_may_see_it(): void
    {
        $kai = Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]);
        StaffOperationalStatus::factory()->create(['staff_id' => $kai->id, 'status' => 'available']);

        // Staff hold staff-status.view by default: the dot and its word.
        $this->actingAs($this->user, 'web')->get('/app/staff')
            ->assertOk()
            ->assertSeeInOrder(['Kai Tan', 'Available']);
        $this->actingAs($this->user, 'web')->get('/app/staff/'.$kai->public_id)
            ->assertOk()
            ->assertSeeInOrder(['Kai Tan', 'Available']);

        // Without staff-status.view, no status (the directory itself still shows).
        $this->app['auth']->forgetGuards();
        $role = Role::query()->create(['name' => 'directory-only', 'label' => 'Directory only']);
        $role->permissions()->sync(Permission::query()->where('name', 'staff.view')->pluck('id'));
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'web')->get('/app/staff')
            ->assertOk()
            ->assertSee('Kai Tan')
            ->assertDontSee('Available');
    }

    public function test_directory_filters_by_status_leave_and_department(): void
    {
        $kai = Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null, 'department_id' => Department::factory()->create(['name' => 'Sales'])->id]);
        StaffOperationalStatus::factory()->create(['staff_id' => $kai->id, 'status' => 'available']);
        $sarah = Staff::factory()->create(['first_name' => 'Sarah', 'last_name' => 'Kim', 'preferred_name' => null]);
        LeaveRequest::factory()->approved()->create(['staff_id' => $sarah->id, 'start_date' => '2026-10-13', 'end_date' => '2026-10-15']);

        $this->actingAs($this->user, 'web');
        Livewire::test(Directory::class)
            ->assertSeeInOrder(['All', 'Available', 'In a meeting', 'Busy', 'In the field', 'Off duty', 'On leave'])
            ->assertSee('All departments')
            ->assertSee('Sarah Kim')
            ->assertSeeInOrder(['Sarah Kim', 'On leave', '15 Oct 2026']) // her leave, with its dates
            ->call('showOnly', 'available')
            ->assertSet('show', 'available')
            ->assertSee('Kai Tan')
            ->assertDontSee('Sarah Kim')
            ->call('showOnly', 'on_leave')
            ->assertSee('Sarah Kim')
            ->assertDontSee('Kai Tan')
            ->call('showOnly', '')
            ->set('department', Department::query()->where('name', 'Sales')->value('public_id'))
            ->assertSee('Kai Tan')
            ->assertDontSee('Sarah Kim');
    }

    public function test_directory_cards_say_what_someone_is_working_on(): void
    {
        $kai = Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]);
        $project = Project::factory()->create(['name' => 'Website Redesign']);
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id]);
        Task::factory()->inProgress()->create(['assignee_staff_id' => $kai->id, 'project_id' => $project->id]);

        $this->actingAs($this->user, 'web')->get('/app/staff')
            ->assertOk()
            ->assertSeeInOrder(['Kai Tan', 'Working on', 'Website Redesign']);
        $this->actingAs($this->user, 'web')->get('/app/staff/'.$kai->public_id)
            ->assertOk()
            ->assertSeeInOrder(['Working on', 'Website Redesign']);
    }

    public function test_directory_pages_by_25(): void
    {
        Staff::factory()->count(30)->create();

        $this->actingAs($this->user, 'web');
        Livewire::test(Directory::class)
            ->assertSee('Page 1 of 2')
            ->call('goToPage', 2)
            ->assertSet('page', 2)
            ->assertSee('Page 2 of 2');
    }

    public function test_directory_without_permission(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/app/staff')
            ->assertOk()
            ->assertSee("You don't have access to the staff directory.");
    }

    public function test_a_colleague_page(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/staff/'.$this->staff->public_id)
            ->assertOk()
            ->assertSeeInOrder(['Lia', 'Amelia Ramos'])
            ->assertSeeInOrder(['Position', 'Field Technician', 'Department', 'Operations', 'Team', 'North Crew', 'Manager', 'Ben Cruz'])
            ->assertSeeInOrder(['Company email', 'lia.ramos@company.test', 'Company phone', '+63 900 000 0042']);

        $this->get('/app/staff/01NOTAREALID00000000000000')->assertOk()->assertSee("This person isn't in the directory.", false);
        $this->get('/app/staff/not-a-valid-id!')->assertNotFound();
    }

    private function apiAnswers(int $status): void
    {
        $this->app->instance(InternalApi::class, new class($status) extends InternalApi
        {
            public function __construct(private readonly int $status) {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                throw new PortalApiException($this->status, 'Failed');
            }
        });
    }

    public function test_an_ended_session_returns_to_login(): void
    {
        // A 401 from the API (InternalApiTest pins that a missing session
        // user gets one) signs the browser out with the mobile app's words.
        $this->apiAnswers(401);
        $this->actingAs($this->user, 'web');

        Livewire::test(Home::class)->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->assertSame('Your session has ended. Please sign in again.', session('status'));
    }

    public function test_a_forbidden_answer_on_my_pages_shows_the_no_profile_state(): void
    {
        $this->apiAnswers(403);
        $this->actingAs($this->user, 'web');

        Livewire::test(Home::class)->assertSee('No staff profile is linked to this account.');
        Livewire::test(Profile::class)->assertSee('No staff profile is linked to this account.');
    }

    public function test_a_failed_load_offers_try_again(): void
    {
        $this->apiAnswers(500);

        $this->actingAs($this->user, 'web');
        Livewire::test(Profile::class)
            ->assertSee('Something went wrong loading your profile.')
            ->assertSee('Try again');
        Livewire::test(Directory::class)->assertSee('Something went wrong loading the staff directory.');
    }
}
