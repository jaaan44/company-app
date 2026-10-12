<?php

namespace Tests\Feature\Api\V1\Scheduling;

use App\Enums\ProjectMembershipRole;
use App\Enums\ScheduleEntryActivityType;
use App\Enums\TaskStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * My schedule (Phase 30A Gate 1, docs/phases/V1_PHASE_30_DEFINITION.md
 * §5.1, R-2…R-7): self-scope for every role, the three sources and the
 * excluded ones, company-day boundaries in Asia/Manila, the total order,
 * the 31-day limit, the no-profile response and a constant query count.
 */
class MyScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        // 2026-10-14 08:00 Manila.
        $this->travelTo(Carbon::parse('2026-10-14 00:00:00', 'UTC'));

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(string $startsAtUtc, string $endsAtUtc, array $attributes = []): ScheduleEntry
    {
        return ScheduleEntry::factory()->create([
            'creator_staff_id' => $this->staff->id,
            'starts_at' => $startsAtUtc,
            'ends_at' => $endsAtUtc,
            'is_all_day' => false,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(string $dueDate, array $attributes = []): Task
    {
        return Task::factory()->create([
            'assignee_staff_id' => $this->staff->id,
            'due_date' => $dueDate,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(string $from = '2026-10-12', string $to = '2026-10-18', ?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->user);

        return $this->getJson("/api/v1/me/schedule?from={$from}&to={$to}")->assertOk()->json('data');
    }

    /**
     * @return list<string>
     */
    private function titles(string $from = '2026-10-12', string $to = '2026-10-18', ?User $as = null): array
    {
        return array_column($this->schedule($from, $to, $as)['items'], 'title');
    }

    // --- Shape -------------------------------------------------------------------

    public function test_the_response_carries_the_company_day_the_range_and_full_items(): void
    {
        $project = Project::factory()->create(['name' => 'Plant Room']);
        $entry = $this->entry('2026-10-14 01:00:00', '2026-10-14 02:30:00', [
            'title' => 'Site visit',
            'description' => 'Bring the keys.',
            'activity_type' => ScheduleEntryActivityType::ClientVisit,
            'project_id' => $project->id,
        ]);

        $data = $this->schedule();

        $this->assertSame(
            ['date' => '2026-10-14', 'timezone' => 'Asia/Manila', 'utc_offset' => '+08:00'],
            $data['company_day'],
        );
        $this->assertSame(['from' => '2026-10-12', 'to' => '2026-10-18'], $data['range']);
        $this->assertSame([[
            'source_type' => 'schedule_entry',
            'public_id' => $entry->public_id,
            'title' => 'Site visit',
            'description' => 'Bring the keys.',
            'activity_type' => 'client_visit',
            'status' => null,
            'task_status' => null,
            'starts_at' => '2026-10-14T01:00:00+00:00',
            'ends_at' => '2026-10-14T02:30:00+00:00',
            'is_all_day' => false,
            'project' => ['public_id' => $project->public_id, 'name' => 'Plant Room'],
        ]], $data['items']);
    }

    public function test_a_task_is_an_all_day_item_on_its_company_day_with_its_status(): void
    {
        $task = $this->task('2026-10-15', ['title' => 'Order filters', 'status' => TaskStatus::InProgress]);

        $item = $this->schedule()['items'][0];

        $this->assertSame('task', $item['source_type']);
        $this->assertSame($task->public_id, $item['public_id']);
        $this->assertSame('in_progress', $item['status']);
        $this->assertSame('in_progress', $item['task_status']);
        $this->assertTrue($item['is_all_day']);
        // 2026-10-15 00:00 and 23:59:59 Manila.
        $this->assertSame('2026-10-14T16:00:00+00:00', $item['starts_at']);
        $this->assertSame('2026-10-15T15:59:59+00:00', $item['ends_at']);
    }

    public function test_approved_leave_is_an_all_day_item_titled_with_its_type(): void
    {
        $type = LeaveType::factory()->create(['name' => 'Vacation leave']);
        LeaveRequest::factory()->approved()->create([
            'staff_id' => $this->staff->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-16',
        ]);

        $item = $this->schedule()['items'][0];

        $this->assertSame('leave', $item['source_type']);
        $this->assertSame('Vacation leave', $item['title']);
        $this->assertSame('approved', $item['status']);
        $this->assertSame('2026-10-14T16:00:00+00:00', $item['starts_at']);
        $this->assertSame('2026-10-16T15:59:59+00:00', $item['ends_at']);
    }

    // --- Sources: what is mine, and what is not ---------------------------------

    public function test_entries_i_created_or_join_are_included_and_project_only_entries_are_not(): void
    {
        $other = Staff::factory()->create();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id]);

        $this->entry('2026-10-14 01:00:00', '2026-10-14 02:00:00', ['title' => 'Mine']);
        $joined = $this->entry('2026-10-14 03:00:00', '2026-10-14 04:00:00', ['title' => 'Joined', 'creator_staff_id' => $other->id]);
        $joined->participants()->attach($this->staff->id);
        $this->entry('2026-10-14 05:00:00', '2026-10-14 06:00:00', ['title' => 'Project only', 'creator_staff_id' => $other->id, 'project_id' => $project->id]);
        $this->entry('2026-10-14 05:00:00', '2026-10-14 06:00:00', ['title' => 'Someone else', 'creator_staff_id' => $other->id]);

        $this->assertSame(['Mine', 'Joined'], $this->titles());
    }

    public function test_only_my_open_assigned_tasks_are_included(): void
    {
        $this->task('2026-10-15', ['title' => 'Open']);
        $this->task('2026-10-15', ['title' => 'Blocked', 'status' => TaskStatus::Blocked]);
        $this->task('2026-10-15', ['title' => 'Done', 'status' => TaskStatus::Completed]);
        $this->task('2026-10-15', ['title' => 'Dropped', 'status' => TaskStatus::Cancelled]);
        $this->task('2026-10-15', ['title' => 'Someone else', 'assignee_staff_id' => Staff::factory()->create()->id]);
        Task::factory()->create(['title' => 'Unassigned', 'due_date' => '2026-10-15', 'assignee_staff_id' => null]);
        $this->task('2026-10-15', ['title' => 'Undated', 'due_date' => null]);

        $this->assertSame(['Blocked', 'Open'], $this->titles());
    }

    public function test_only_my_approved_leave_is_included_and_milestones_never_are(): void
    {
        $type = LeaveType::factory()->create(['name' => 'Sick leave']);
        LeaveRequest::factory()->approved()->create(['staff_id' => $this->staff->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        LeaveRequest::factory()->create(['staff_id' => $this->staff->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        LeaveRequest::factory()->rejected()->create(['staff_id' => $this->staff->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        LeaveRequest::factory()->approved()->create(['start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id]);
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'due_date' => '2026-10-15', 'title' => 'Milestone']);

        $this->assertSame(['Sick leave'], $this->titles());
    }

    // --- Self-scope for every role (R-2) ----------------------------------------

    public function test_no_role_widens_the_schedule(): void
    {
        $colleague = Staff::factory()->create();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $colleague->id]);
        $this->task('2026-10-15', ['title' => "Colleague's task", 'assignee_staff_id' => $colleague->id, 'project_id' => $project->id]);
        $this->entry('2026-10-14 01:00:00', '2026-10-14 02:00:00', ['title' => "Colleague's meeting", 'creator_staff_id' => $colleague->id, 'project_id' => $project->id]);
        LeaveRequest::factory()->approved()->create(['staff_id' => $colleague->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);

        $manager = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $manager->id]);
        $colleague->update(['manager_id' => $managerStaff->id]);
        $admin = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $admin->id]);
        $lead = User::factory()->staff()->create();
        $leadStaff = Staff::factory()->create(['user_id' => $lead->id]);
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $leadStaff->id, 'role' => ProjectMembershipRole::ProjectLead]);
        $this->task('2026-10-16', ['title' => "Lead's task", 'assignee_staff_id' => $leadStaff->id]);

        $this->assertSame([], $this->titles(as: $manager), 'Manager (tasks.view, projects.view, direct report)');
        $this->assertSame([], $this->titles(as: $admin), 'Administrator');
        $this->assertSame(["Lead's task"], $this->titles(as: $lead), 'Project Lead: own task only');
    }

    public function test_unknown_parameters_cannot_change_the_subject(): void
    {
        $colleague = Staff::factory()->create();
        $this->task('2026-10-15', ['title' => "Colleague's task", 'assignee_staff_id' => $colleague->id]);
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/me/schedule?from=2026-10-12&to=2026-10-18&staff={$colleague->public_id}&assignee={$colleague->public_id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    // --- Company-day boundaries (R-4) --------------------------------------------

    public function test_an_early_morning_manila_meeting_belongs_to_its_manila_day(): void
    {
        // 07:00–07:30 Manila on 14 Oct = 23:00–23:30 UTC on 13 Oct.
        $this->entry('2026-10-13 23:00:00', '2026-10-13 23:30:00', ['title' => 'Early']);

        $this->assertSame(['Early'], $this->titles('2026-10-14', '2026-10-14'));
        $this->assertSame([], $this->titles('2026-10-13', '2026-10-13'));
    }

    public function test_a_late_evening_manila_meeting_stays_on_its_manila_day(): void
    {
        // 23:30–23:59 Manila on 14 Oct = 15:30–15:59 UTC on 14 Oct.
        $this->entry('2026-10-14 15:30:00', '2026-10-14 15:59:00', ['title' => 'Late']);

        $this->assertSame(['Late'], $this->titles('2026-10-14', '2026-10-14'));
        $this->assertSame([], $this->titles('2026-10-15', '2026-10-15'));
    }

    public function test_an_entry_overlapping_the_range_edges_is_included(): void
    {
        // From 20:00 Manila on 11 Oct to 09:00 Manila on 12 Oct.
        $this->entry('2026-10-11 12:00:00', '2026-10-12 01:00:00', ['title' => 'Overnight']);
        // A three-day conference over the whole range end.
        $this->entry('2026-10-18 00:00:00', '2026-10-20 10:00:00', ['title' => 'Conference']);

        $this->assertSame(['Overnight', 'Conference'], $this->titles());
        $this->assertSame([], $this->titles('2026-10-13', '2026-10-17'));
    }

    public function test_tasks_and_leave_on_the_range_edges_are_included_and_outside_excluded(): void
    {
        $this->task('2026-10-11', ['title' => 'Before']);
        $this->task('2026-10-12', ['title' => 'First day']);
        $this->task('2026-10-18', ['title' => 'Last day']);
        $this->task('2026-10-19', ['title' => 'After']);
        $type = LeaveType::factory()->create(['name' => 'Leave across']);
        LeaveRequest::factory()->approved()->create(['staff_id' => $this->staff->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-10', 'end_date' => '2026-10-12']);

        $this->assertSame(['Leave across', 'First day', 'Last day'], $this->titles());
    }

    // --- Total order (R-6) -------------------------------------------------------

    public function test_items_come_in_the_total_order(): void
    {
        // All on 15 Oct (Manila): its start is 2026-10-14 16:00 UTC.
        $type = LeaveType::factory()->create(['name' => 'B leave']);
        LeaveRequest::factory()->approved()->create(['staff_id' => $this->staff->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        $this->task('2026-10-15', ['title' => 'B task']);
        $this->task('2026-10-15', ['title' => 'A task']);
        $this->entry('2026-10-14 16:00:00', '2026-10-15 15:59:59', ['title' => 'Z all-day entry', 'is_all_day' => true]);
        $this->entry('2026-10-14 16:00:00', '2026-10-14 17:00:00', ['title' => 'A midnight meeting']);
        $this->entry('2026-10-15 01:00:00', '2026-10-15 02:00:00', ['title' => '9']);
        $this->entry('2026-10-15 01:00:00', '2026-10-15 02:00:00', ['title' => '10']);

        $this->assertSame([
            'Z all-day entry',      // same start: all-day first, entries first
            'A task',               // then tasks, by title
            'B task',
            'B leave',              // then leave
            'A midnight meeting',   // the timed item at that start
            '10',                   // later start; byte-wise title ("10" < "9"),
            '9',                    // never PHP's numeric string comparison
        ], $this->titles());
    }

    public function test_equal_items_are_ordered_by_public_id(): void
    {
        // Created first (lower id) but with the larger public_id, so the
        // database's own order can't stand in for the tie-breaker.
        $first = $this->task('2026-10-15', ['title' => 'Same']);
        $first->forceFill(['public_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])->save();
        $second = $this->task('2026-10-15', ['title' => 'Same']);
        $second->forceFill(['public_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])->save();

        $this->assertSame(
            ['01AAAAAAAAAAAAAAAAAAAAAAAA', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'],
            array_column($this->schedule()['items'], 'public_id'),
        );
    }

    // --- Validation (R-6) --------------------------------------------------------

    public function test_from_and_to_are_required_company_dates(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/me/schedule')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/v1/me/schedule?from=2026-10-14T00:00:00Z&to=2026-10-15')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/me/schedule?from=2026-10-15&to=2026-10-14')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_the_range_is_at_most_31_days(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/me/schedule?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->getJson('/api/v1/me/schedule?from=2026-10-01&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'The range can be at most 31 days.']);
    }

    // --- No profile (R-7), auth --------------------------------------------------

    public function test_without_a_staff_record_items_is_null(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/v1/me/schedule?from=2026-10-12&to=2026-10-18')
            ->assertOk()
            ->assertJsonPath('data.items', null)
            ->assertJsonPath('data.company_day.date', '2026-10-14');
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson('/api/v1/me/schedule?from=2026-10-12&to=2026-10-18')->assertUnauthorized();
    }

    // --- Query count -------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_items(): void
    {
        $count = function (): int {
            // A fresh User each time, so both requests load its Staff record.
            Sanctum::actingAs($this->user->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/me/schedule?from=2026-10-12&to=2026-10-18')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $seed = function (int $n): void {
            $project = Project::factory()->create();
            for ($i = 0; $i < $n; $i++) {
                $e = $this->entry('2026-10-14 01:00:00', '2026-10-14 02:00:00', ['project_id' => $project->id, 'creator_staff_id' => Staff::factory()->create()->id]);
                $e->participants()->attach($this->staff->id);
                $this->task('2026-10-15', ['project_id' => $project->id]);
                LeaveRequest::factory()->approved()->create(['staff_id' => $this->staff->id, 'start_date' => '2026-10-16', 'end_date' => '2026-10-16']);
            }
        };

        $seed(1);
        $few = $count();
        $seed(5);
        $many = $count();

        $this->assertSame($few, $many);
    }
}
