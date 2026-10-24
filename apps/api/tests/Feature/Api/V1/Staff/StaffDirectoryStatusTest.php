<?php

namespace Tests\Feature\Api\V1\Staff;

use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffOperationalStatus;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 37E (DEC-072): the directory's status and leave filters, and its
 * `current_leave` and `working_on` fields, with who may see each.
 */
class StaffDirectoryStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-24 02:00:00', 'UTC')); // Sat 24 Oct, 10:00 Manila

        $this->user = User::factory()->staff()->create();
        $this->me = Staff::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Alex', 'last_name' => 'Morgan', 'preferred_name' => null]);
    }

    private function person(string $first, ?string $status = null): Staff
    {
        $staff = Staff::factory()->create(['first_name' => $first, 'last_name' => 'Test', 'preferred_name' => null]);
        if ($status !== null) {
            StaffOperationalStatus::factory()->create(['staff_id' => $staff->id, 'status' => $status]);
        }

        return $staff;
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return collect($this->getJson('/api/v1/staff'.$query)->assertOk()->json('data'))->pluck('first_name')->sort()->values()->all();
    }

    public function test_filters_by_current_operational_status(): void
    {
        $maria = $this->person('Maria', 'busy');
        StaffOperationalStatus::factory()->create(['staff_id' => $maria->id, 'status' => 'available', 'created_at' => now()->addMinute()]); // her latest
        $this->person('John', 'in_meeting');
        $this->person('Anna');
        Sanctum::actingAs($this->user);

        $this->assertSame(['Maria'], $this->names('?operational_status=available'));
        $this->assertSame(['John'], $this->names('?operational_status=in_meeting'));
        $this->assertSame([], $this->names('?operational_status=busy')); // Maria was busy, but isn't now
        $this->getJson('/api/v1/staff?operational_status=asleep')->assertUnprocessable();
    }

    public function test_filters_by_leave_covering_today_and_returns_its_dates(): void
    {
        $sarah = $this->person('Sarah');
        LeaveRequest::factory()->approved()->create(['staff_id' => $sarah->id, 'start_date' => '2026-10-24', 'end_date' => '2026-10-26', 'reason' => 'Private reason']);
        $pending = $this->person('Pending');
        LeaveRequest::factory()->create(['staff_id' => $pending->id, 'start_date' => '2026-10-23', 'end_date' => '2026-10-25']);
        $ended = $this->person('Ended');
        LeaveRequest::factory()->approved()->create(['staff_id' => $ended->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-23']);
        Sanctum::actingAs($this->user);

        $this->assertSame(['Sarah'], $this->names('?on_leave=1'));
        $this->assertNotContains('Sarah', $this->names('?on_leave=0'));

        $this->getJson('/api/v1/staff/'.$sarah->public_id)->assertOk()
            ->assertJsonPath('data.current_leave', ['start_date' => '2026-10-24', 'end_date' => '2026-10-26'])
            ->assertJsonMissing(['reason' => 'Private reason']);
        $this->getJson('/api/v1/staff/'.$pending->public_id)->assertOk()->assertJsonPath('data.current_leave', null);
        $this->getJson('/api/v1/staff/'.$ended->public_id)->assertOk()->assertJsonPath('data.current_leave', null);
    }

    public function test_status_and_leave_need_staff_status_view(): void
    {
        $sarah = $this->person('Sarah', 'available');
        LeaveRequest::factory()->approved()->create(['staff_id' => $sarah->id, 'start_date' => '2026-10-24', 'end_date' => '2026-10-24']);
        $role = Role::query()->create(['name' => 'directory-only', 'label' => 'Directory only']);
        $role->permissions()->sync(Permission::query()->where('name', 'staff.view')->pluck('id'));
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->getJson('/api/v1/staff?operational_status=available')->assertForbidden();
        $this->getJson('/api/v1/staff?on_leave=1')->assertForbidden();
        $this->getJson('/api/v1/staff/'.$sarah->public_id)->assertOk()->assertJsonMissingPath('data.current_leave');
    }

    public function test_working_on_names_a_project_the_requester_may_see(): void
    {
        $john = $this->person('John');
        $shared = Project::factory()->create(['name' => 'Website Redesign']);
        $secret = Project::factory()->create(['name' => 'Secret Merger']);
        ProjectMembership::factory()->create(['project_id' => $shared->id, 'staff_id' => $this->me->id]);
        Task::factory()->inProgress()->create(['assignee_staff_id' => $john->id, 'project_id' => $shared->id, 'updated_at' => now()->subHour()]);

        $kai = $this->person('Kai');
        Task::factory()->inProgress()->create(['assignee_staff_id' => $kai->id, 'project_id' => $secret->id]);

        $nobody = $this->person('Nobody');
        Task::factory()->create(['assignee_staff_id' => $nobody->id, 'project_id' => $shared->id]); // to do, not in progress
        Task::factory()->inProgress()->create(['assignee_staff_id' => $nobody->id]); // no project

        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/staff/'.$john->public_id)->assertOk()
            ->assertJsonPath('data.working_on', ['public_id' => $shared->public_id, 'name' => 'Website Redesign']);
        $this->getJson('/api/v1/staff/'.$kai->public_id)->assertOk()->assertJsonPath('data.working_on', null); // not her project
        $this->getJson('/api/v1/staff/'.$nobody->public_id)->assertOk()->assertJsonPath('data.working_on', null);
        $this->getJson('/api/v1/staff')->assertOk()->assertJsonMissing(['name' => 'Secret Merger']);

        // projects.view (a Manager) sees every project.
        Sanctum::actingAs(User::factory()->manager()->create());
        $this->getJson('/api/v1/staff/'.$kai->public_id)->assertOk()->assertJsonPath('data.working_on.name', 'Secret Merger');
    }

    public function test_working_on_is_the_most_recently_updated_in_progress_task(): void
    {
        $john = $this->person('John');
        $old = Project::factory()->create(['name' => 'Old Project']);
        $new = Project::factory()->create(['name' => 'New Project']);
        Task::factory()->inProgress()->create(['assignee_staff_id' => $john->id, 'project_id' => $old->id, 'updated_at' => now()->subDay()]);
        Task::factory()->inProgress()->create(['assignee_staff_id' => $john->id, 'project_id' => $new->id, 'updated_at' => now()->subHour()]);
        Sanctum::actingAs(User::factory()->manager()->create());

        $this->getJson('/api/v1/staff/'.$john->public_id)->assertOk()->assertJsonPath('data.working_on.name', 'New Project');
    }

    public function test_the_list_needs_a_constant_number_of_queries(): void
    {
        Sanctum::actingAs($this->user);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/staff')->assertOk();

            return count(DB::getQueryLog());
        };

        $add = function (string $name): void {
            $staff = $this->person($name, 'busy');
            LeaveRequest::factory()->approved()->create(['staff_id' => $staff->id, 'start_date' => '2026-10-24', 'end_date' => '2026-10-24']);
            Task::factory()->inProgress()->create(['assignee_staff_id' => $staff->id, 'project_id' => Project::factory()->create()->id]);
        };

        $add('One');
        $count(); // the first request also loads the user's role and permissions
        $few = $count();
        foreach (['Two', 'Three', 'Four', 'Five'] as $name) {
            $add($name);
        }

        $this->assertSame($few, $count());
    }
}
