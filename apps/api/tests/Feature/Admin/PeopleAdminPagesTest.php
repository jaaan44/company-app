<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Livewire\Account\ChangePassword;
use App\Livewire\Admin\AccountForm;
use App\Livewire\Admin\Organization;
use App\Livewire\Admin\StaffForm;
use App\Livewire\Admin\StaffList;
use App\Livewire\Admin\StaffRecord;
use App\Livewire\Admin\Users;
use App\Livewire\Auth\LoginForm;
use App\Models\Department;
use App\Models\Position;
use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\Team;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin Backoffice → People and sign-in accounts (Phase 36A, DEC-066).
 */
class PeopleAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-16 02:00:00', 'UTC'));
        $this->admin = User::factory()->administrator()->create(['name' => 'Ada Admin', 'email' => 'ada@company.test']);
    }

    private const PAGES = ['/admin/people/staff', '/admin/people/staff/new', '/admin/people/organization', '/admin/administration/users/new'];

    /**
     * A fake API that refuses every write with $status / $message / $errors
     * (the harness would otherwise surface a 422 under the API's field name).
     */
    private function refusingApi(int $status, string $message, array $errors = [], array $reads = []): void
    {
        $this->app->instance(InternalApi::class, new class($status, $message, $errors, $reads) extends InternalApi
        {
            public function __construct(private int $status, private string $message, private array $errors, private array $reads) {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method !== 'GET') {
                    throw new PortalApiException($this->status, $this->message, $this->errors);
                }

                return $this->reads[$path] ?? ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'roles' => []]];
            }
        });
    }

    // ---- Access and navigation --------------------------------------------

    public function test_only_administrators_open_the_people_screens_and_the_section_links_them(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertRedirect('/login');
        }
        foreach ([User::factory()->manager()->create(), User::factory()->staff()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            foreach (self::PAGES as $page) {
                $this->actingAs($user, 'web')->get($page)->assertForbidden();
            }
        }

        $this->app['auth']->forgetGuards();
        foreach (self::PAGES as $page) {
            $this->actingAs($this->admin, 'web')->get($page)->assertOk();
        }
        $this->get('/admin/people')
            ->assertSee('href="'.route('admin.staff').'"', false)
            ->assertSee('href="'.route('admin.organization').'"', false)
            ->assertDontSee('Coming in');
    }

    // ---- Staff records ------------------------------------------------------

    public function test_the_staff_list_searches_and_filters_by_status(): void
    {
        Staff::factory()->create(['first_name' => 'Lia', 'last_name' => 'Ramos', 'employee_number' => 'E-001']);
        Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'employee_number' => 'E-002', 'status' => 'separated', 'separation_date' => '2026-09-30']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(StaffList::class)
            ->assertSeeInOrder(['New staff record', 'Lia Ramos', 'E-001', 'No sign-in', 'Kai Tan', 'Separated'])
            ->set('search', 'kai')->assertSee('Kai Tan')->assertDontSee('Lia Ramos')
            ->set('search', '')->set('status', 'active')->assertSee('Lia Ramos')->assertDontSee('Kai Tan');
    }

    public function test_new_staff_record_checks_the_fields_then_saves(): void
    {
        $department = Department::factory()->create(['name' => 'Operations']);
        $manager = Staff::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(StaffForm::class)
            ->call('save')
            ->assertHasErrors(['employeeNumber' => 'Give the employee number.', 'firstName' => 'Give the first name.', 'lastName' => 'Give the last name.'])
            ->set('status', 'separated')->call('save')->assertHasErrors(['separationDate' => 'Give the separation date.'])
            ->set('status', 'active')
            ->set('employeeNumber', 'E-100')->set('firstName', 'Nina')->set('lastName', 'Novak')->set('companyEmail', 'nina@company.test')
            ->set('departmentId', $department->public_id)
            ->set('managerSearch', 'ben')->assertSee('Ben Cruz')
            ->call('chooseManager', $manager->public_id, 'Ben Cruz')
            ->call('save')->assertHasNoErrors();

        $nina = Staff::query()->where('employee_number', 'E-100')->sole();
        $this->assertSame($department->id, $nina->department_id);
        $this->assertSame($manager->id, $nina->manager_id);
    }

    public function test_editing_a_staff_record_saves_and_shows_the_apis_field_messages(): void
    {
        $nina = Staff::factory()->create(['first_name' => 'Nina', 'last_name' => 'Novak', 'employee_number' => 'E-100']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(StaffForm::class, ['publicId' => $nina->public_id])
            ->assertSet('firstName', 'Nina')
            ->set('preferredName', 'Nin')->set('status', 'separated')->set('separationDate', '2026-10-15')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.staff.show', $nina->public_id));
        $this->assertSame('separated', $nina->fresh()->status->value);

        $this->refusingApi(422, 'Invalid.', ['employee_number' => ['The employee number has already been taken.']], [
            '/staff/'.$nina->public_id => ['data' => ['first_name' => 'Nina', 'last_name' => 'Novak', 'employee_number' => 'E-100', 'status' => 'active']],
        ]);
        Livewire::test(StaffForm::class, ['publicId' => $nina->public_id])->call('save')
            ->assertHasErrors(['employeeNumber' => 'The employee number has already been taken.']);
    }

    public function test_the_staff_record_shows_details_account_status_and_check_ins(): void
    {
        $lia = Staff::factory()->create(['first_name' => 'Lia', 'last_name' => 'Ramos', 'company_email' => 'lia@company.test']);
        $checkIn = StaffCheckIn::factory()->for($lia)->create(['location_label' => 'Cold room', 'created_at' => '2026-10-16 01:00:00']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(StaffRecord::class, ['publicId' => $lia->public_id])
            ->assertSeeInOrder(['Lia Ramos', 'Edit', 'Delete', 'Details', 'lia@company.test', 'Sign-in account', 'No sign-in account', 'Create a sign-in account', 'Status', 'No status set yet.', 'Check-ins', 'Cold room'])
            ->assertSee(route('admin.users.new', ['staff' => $lia->public_id]), false)
            ->set('newStatus', 'in_field')->call('setStatus')->assertSet('savedMessage', 'Status changed.')
            ->assertSee('In the field')
            ->call('removeCheckIn', $checkIn->public_id)->assertSet('savedMessage', 'Check-in removed.')
            ->assertSee('No check-ins.');
        $this->assertSame('in_field', $lia->latestOperationalStatus()->first()->status->value);
        $this->assertModelMissing($checkIn);

        $lia->forceFill(['user_id' => User::factory()->staff()->create(['email' => 'lia.sign-in@company.test'])->id])->save();
        Livewire::test(StaffRecord::class, ['publicId' => $lia->public_id])
            ->assertSee('lia.sign-in@company.test')->assertSee('Manage the account')->assertDontSee('Create a sign-in account');
    }

    public function test_deleting_a_staff_record_with_history_shows_why_and_one_without_is_deleted(): void
    {
        $ben = Staff::factory()->create();
        Staff::factory()->create(['manager_id' => $ben->id]);
        $empty = Staff::factory()->create();
        $this->actingAs($this->admin, 'web');

        Livewire::test(StaffRecord::class, ['publicId' => $ben->public_id])->call('delete')
            ->assertSee('This staff member still has direct reports and cannot be deleted. To keep the history, set their status to Separated instead.')
            ->assertNoRedirect();
        $this->assertModelExists($ben);

        Livewire::test(StaffRecord::class, ['publicId' => $empty->public_id])->call('delete')->assertRedirect(route('admin.staff'));
        $this->assertModelMissing($empty);
    }

    // ---- Departments, teams and positions ---------------------------------

    public function test_departments_teams_and_positions_add_edit_and_remove(): void
    {
        $this->actingAs($this->admin, 'web');

        $page = Livewire::test(Organization::class)
            ->call('add')->call('save')->assertHasErrors(['name' => 'Give the department a name.'])
            ->set('name', 'Operations')->call('save')->assertSet('savedMessage', 'Department added.')->assertSee('Operations');
        $operations = Department::query()->where('name', 'Operations')->sole();

        $page->call('edit', $operations->public_id)->set('name', 'Field operations')->call('save')
            ->assertSet('savedMessage', 'Changes saved.')->assertSee('Field operations');

        $page->set('tab', 'teams')->call('add')->set('name', 'North Crew')->set('departmentId', $operations->public_id)->call('save')
            ->assertSee('North Crew')->assertSee('Field operations');
        $this->assertSame($operations->id, Team::query()->where('name', 'North Crew')->sole()->department_id);

        $page->set('tab', 'departments')->call('remove', $operations->public_id)
            ->assertSee('This department still has teams, positions, or staff assigned to it and cannot be deleted. To keep it out of use, set it to Inactive instead.');
        $this->assertModelExists($operations);

        $page->set('tab', 'positions')->call('add')->set('name', 'Technician')->call('save')->assertSee('Technician');
        $technician = Position::query()->where('title', 'Technician')->sole();
        $page->call('remove', $technician->public_id)->assertSet('savedMessage', 'Position removed.');
        $this->assertModelMissing($technician);
    }

    // ---- Sign-in accounts ---------------------------------------------------

    public function test_a_new_account_for_a_staff_record_shows_its_temporary_password_once(): void
    {
        $lia = Staff::factory()->create(['first_name' => 'Lia', 'last_name' => 'Ramos', 'company_email' => 'lia@company.test']);
        $this->actingAs($this->admin, 'web');

        $page = Livewire::test(AccountForm::class, ['staff' => $lia->public_id])
            ->assertSet('name', 'Lia Ramos')->assertSet('email', 'lia@company.test')->assertSet('staffName', $lia->displayName())
            ->set('role', 'manager')->call('save')
            ->assertSee('Account created')->assertSeeText('It won\'t be shown again.', false);

        $user = User::query()->where('email', 'lia@company.test')->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertSame($user->id, $lia->fresh()->user_id);

        preg_match('/id="temporary-password">([^<]+)</', $page->html(), $match);
        $temporary = $match[1] ?? '';
        $this->assertTrue(Hash::check($temporary, $user->password));
        $this->assertStringNotContainsString($temporary, json_encode($page->snapshot, JSON_THROW_ON_ERROR));

        // Any later request no longer has it.
        $page->call('save')->assertDontSee($temporary)->assertSee('The temporary password is no longer shown');
    }

    public function test_a_new_account_shows_the_apis_messages(): void
    {
        $this->actingAs($this->admin, 'web');
        Livewire::test(AccountForm::class)->call('save')
            ->assertHasErrors(['name' => 'Give the person\'s name.', 'email' => 'Enter a valid email address. They sign in with it.']);

        $this->refusingApi(422, 'Invalid.', ['email' => ['An account with this email already exists.']]);
        Livewire::test(AccountForm::class)->set('name', 'Ada')->set('email', 'ada@company.test')->call('save')
            ->assertHasErrors(['email' => 'An account with this email already exists.'])->assertSet('created', null);
    }

    public function test_users_resets_a_password_and_suspends_and_reactivates(): void
    {
        $lia = User::factory()->staff()->create(['name' => 'Lia Ramos', 'email' => 'lia@company.test', 'password' => 'their-own-password']);
        $this->actingAs($this->admin, 'web');

        $page = Livewire::test(Users::class)
            ->assertSee('New account')->assertSeeText('Reset password for Lia Ramos')->assertDontSeeText('Reset password for Ada Admin')
            ->call('resetPassword', $lia->public_id)
            ->assertSee('Password reset for Lia Ramos');
        preg_match('/id="temporary-password">([^<]+)</', $page->html(), $match);
        $this->assertTrue(Hash::check($match[1] ?? '', $lia->fresh()->password));
        $this->assertStringNotContainsString($match[1], json_encode($page->snapshot, JSON_THROW_ON_ERROR));
        $page->assertSee('Must choose a password')->call('dismissReset')->assertDontSee('Password reset for');

        $page->call('setStatus', $lia->public_id, 'suspended')->assertSet('savedMessage', 'Lia Ramos is suspended and signed out everywhere.')
            ->assertSee('Suspended')->assertSee('Reactivate Lia Ramos');
        $this->assertSame(AccountStatus::Suspended, $lia->fresh()->status);
        $page->call('setStatus', $lia->public_id, 'active')->assertSet('savedMessage', 'Lia Ramos can sign in again.');
        $this->assertSame(AccountStatus::Active, $lia->fresh()->status);
    }

    public function test_a_refused_account_action_shows_the_apis_message(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->refusingApi(422, 'Invalid.', ['status' => ['You can\'t suspend or reactivate your own account.']]);

        Livewire::test(Users::class)->call('setStatus', $this->admin->public_id, 'suspended')
            ->assertSee('You can\'t suspend or reactivate your own account.')->assertSet('savedMessage', null);
    }

    // ---- Choosing and changing a password ---------------------------------

    public function test_a_new_account_signs_in_chooses_its_password_and_then_reaches_the_portal(): void
    {
        $user = User::factory()->staff()->create(['email' => 'nina@company.test', 'password' => 'Temp-Pass-Word-1', 'must_change_password' => true]);

        Livewire::test(LoginForm::class)->set('email', 'nina@company.test')->set('password', 'Temp-Pass-Word-1')
            ->call('login')->assertRedirect(route('password.choose'));

        $this->actingAs($user, 'web');
        $this->get('/app/tasks')->assertRedirect(route('password.choose'));
        $this->get('/password')->assertOk()->assertSee('Choose your password')->assertSee('Temporary password');

        Livewire::test(ChangePassword::class, ['forced' => true])
            ->call('save')->assertHasErrors(['currentPassword' => 'Enter the temporary password you were given.', 'password' => 'Use at least 12 characters.'])
            ->set('currentPassword', 'Temp-Pass-Word-1')->set('password', 'my-own-long-password')->set('passwordConfirmation', 'something-else')
            ->call('save')->assertHasErrors(['passwordConfirmation' => 'The two new passwords don\'t match.'])
            ->set('passwordConfirmation', 'my-own-long-password')->call('save')->assertHasNoErrors()->assertRedirect(route('portal.home'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('my-own-long-password', $user->password));
        $this->get('/password')->assertRedirect(route('portal.home'));
    }

    public function test_change_password_is_on_the_profile_and_shows_the_apis_messages(): void
    {
        $user = User::factory()->staff()->create();
        $this->actingAs($user, 'web');
        $this->get('/app/profile')->assertSee('href="'.route('portal.password').'"', false);
        $this->get('/app/profile/password')->assertOk()->assertSee('Current password');

        $this->refusingApi(422, 'Invalid.', ['current_password' => ['That isn\'t your current password.']]);
        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'wrong')->set('password', 'my-own-long-password')->set('passwordConfirmation', 'my-own-long-password')
            ->call('save')->assertHasErrors(['currentPassword' => 'That isn\'t your current password.'])
            ->assertSet('password', '')->assertNoRedirect();

        $this->refusingApi(429, 'Too Many Attempts.');
        Livewire::test(ChangePassword::class)
            ->set('currentPassword', 'wrong')->set('password', 'my-own-long-password')->set('passwordConfirmation', 'my-own-long-password')
            ->call('save')->assertSee('Too many attempts. Wait a minute and try again.');
    }
}
