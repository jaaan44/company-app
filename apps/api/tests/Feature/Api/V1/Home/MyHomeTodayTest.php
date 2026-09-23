<?php

namespace Tests\Feature\Api\V1\Home;

use App\Enums\ScheduleEntryActivityType;
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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Employee Home (Phase 27, DEC-052) — the Today section: ownership,
 * company-timezone day boundaries, overlap, ordering, limit, and the
 * approved exclusions (leave, milestones, project-only visibility).
 * See docs/phases/V1_PHASE_27_DEFINITION.md §6.3.
 */
class MyHomeTodayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-09-24 08:00:00', 'UTC'));

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(string $startsAt, string $endsAt, array $attributes = []): ScheduleEntry
    {
        return ScheduleEntry::factory()->create([
            'creator_staff_id' => $this->staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
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
    private function today(): array
    {
        Sanctum::actingAs($this->user);

        return $this->getJson('/api/v1/me/home')->assertOk()->json('data.today');
    }

    /**
     * @return list<string>
     */
    private function todayTitles(): array
    {
        return array_column($this->today()['items'], 'title');
    }

    // --- Schedule entry ownership ---------------------------------------------

    public function test_an_entry_the_employee_created_is_included(): void
    {
        $entry = $this->entry('2026-09-24 09:00:00', '2026-09-24 10:30:00', [
            'title' => 'Site visit',
            'activity_type' => ScheduleEntryActivityType::ClientVisit,
        ]);

        $this->assertSame([[
            'source_type' => 'schedule_entry',
            'public_id' => $entry->public_id,
            'title' => 'Site visit',
            'activity_type' => 'client_visit',
            'task_status' => null,
            'starts_at' => '2026-09-24T09:00:00+00:00',
            'ends_at' => '2026-09-24T10:30:00+00:00',
            'is_all_day' => false,
        ]], $this->today()['items']);
    }

    public function test_an_entry_the_employee_participates_in_is_included(): void
    {
        $other = Staff::factory()->create();
        $entry = $this->entry('2026-09-24 09:00:00', '2026-09-24 10:00:00', ['creator_staff_id' => $other->id, 'title' => 'Joined']);
        $entry->participants()->attach($this->staff->id);

        $this->assertSame(['Joined'], $this->todayTitles());
    }

    public function test_a_project_entry_visible_only_through_membership_is_excluded(): void
    {
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id]);
        $other = Staff::factory()->create();
        $this->entry('2026-09-24 09:00:00', '2026-09-24 10:00:00', ['creator_staff_id' => $other->id, 'project_id' => $project->id]);

        $this->assertSame(['total_count' => 0, 'items' => []], $this->today());
    }

    public function test_another_employees_private_entry_is_excluded(): void
    {
        $other = Staff::factory()->create();
        $this->entry('2026-09-24 09:00:00', '2026-09-24 10:00:00', ['creator_staff_id' => $other->id]);

        $this->assertSame(0, $this->today()['total_count']);
    }

    // --- Overlap semantics -------------------------------------------------

    public function test_overlap_with_the_company_day_is_inclusive_at_both_boundaries(): void
    {
        $this->entry('2026-09-23 22:00:00', '2026-09-24 00:00:00', ['title' => 'ends exactly at day start']);
        $this->entry('2026-09-24 23:59:59', '2026-09-25 01:00:00', ['title' => 'starts exactly at day end']);
        $this->entry('2026-09-23 22:00:00', '2026-09-23 23:59:59', ['title' => 'ends before the day']);
        $this->entry('2026-09-25 00:00:00', '2026-09-25 01:00:00', ['title' => 'starts after the day']);

        $this->assertSame(['ends exactly at day start', 'starts exactly at day end'], $this->todayTitles());
    }

    public function test_a_multi_day_entry_appears_on_every_day_it_overlaps(): void
    {
        $this->entry('2026-09-23 09:00:00', '2026-09-26 17:00:00', ['title' => 'Conference']);

        $this->assertSame(['Conference'], $this->todayTitles());
    }

    public function test_an_all_day_entry_appears_only_on_its_own_day(): void
    {
        $this->entry('2026-09-24 00:00:00', '2026-09-24 23:59:59', ['title' => 'Today all day', 'is_all_day' => true]);
        $this->entry('2026-09-25 00:00:00', '2026-09-25 23:59:59', ['title' => 'Tomorrow all day', 'is_all_day' => true]);

        $this->assertSame(['Today all day'], $this->todayTitles());
    }

    // --- Task assignment -------------------------------------------------------

    public function test_an_open_task_assigned_to_the_employee_due_today_is_included_as_an_all_day_item(): void
    {
        $task = $this->task('2026-09-24', ['title' => 'Replace filter']);
        $task->update(['status' => 'blocked']);

        $this->assertSame([[
            'source_type' => 'task',
            'public_id' => $task->public_id,
            'title' => 'Replace filter',
            'activity_type' => null,
            'task_status' => 'blocked',
            'starts_at' => '2026-09-24T00:00:00+00:00',
            'ends_at' => '2026-09-24T23:59:59+00:00',
            'is_all_day' => true,
        ]], $this->today()['items']);
    }

    public function test_terminal_other_day_undated_and_other_peoples_tasks_are_excluded(): void
    {
        Task::factory()->completed()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => '2026-09-24']);
        Task::factory()->cancelled()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => '2026-09-24']);
        $this->task('2026-09-23');
        $this->task('2026-09-25');
        Task::factory()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => null]);
        Task::factory()->create(['assignee_staff_id' => Staff::factory()->create()->id, 'due_date' => '2026-09-24']);

        $this->assertSame(['total_count' => 0, 'items' => []], $this->today());
    }

    public function test_a_project_task_assigned_to_someone_else_is_excluded_even_for_a_project_lead(): void
    {
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id]);
        Task::factory()->create(['project_id' => $project->id, 'assignee_staff_id' => null, 'due_date' => '2026-09-24']);

        $this->assertSame(0, $this->today()['total_count']);
    }

    // --- Approved exclusions ----------------------------------------------

    public function test_approved_leave_covering_today_never_appears(): void
    {
        LeaveRequest::factory()->approved()->create([
            'staff_id' => $this->staff->id,
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-25',
            'total_days' => 3,
        ]);

        $this->assertSame(['total_count' => 0, 'items' => []], $this->today());
    }

    public function test_project_milestones_never_appear(): void
    {
        $project = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id]);
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'due_date' => '2026-09-24']);

        $this->assertSame(['total_count' => 0, 'items' => []], $this->today());
    }

    // --- Company timezone --------------------------------------------------

    public function test_the_company_day_follows_the_configured_company_timezone_not_utc(): void
    {
        config(['scheduling.company_timezone' => 'Asia/Tokyo']); // UTC+9, no DST
        // 16:30 UTC on the 24th is 01:30 on the 25th in the company timezone.
        $this->travelTo(Carbon::parse('2026-09-24 16:30:00', 'UTC'));

        $this->task('2026-09-25', ['title' => 'Due on the company date']);
        $this->task('2026-09-24', ['title' => 'Due on the UTC date']);
        $this->entry('2026-09-24 23:00:00', '2026-09-24 23:30:00', ['title' => 'Company-day morning']);
        $this->entry('2026-09-24 10:00:00', '2026-09-24 11:00:00', ['title' => 'Previous company day']);

        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/v1/me/home')->assertOk()->json('data');

        $this->assertSame([
            'date' => '2026-09-25',
            'timezone' => 'Asia/Tokyo',
            'starts_at' => '2026-09-24T15:00:00+00:00',
            'ends_at' => '2026-09-25T14:59:59+00:00',
            'utc_offset' => '+09:00',
        ], $data['company_day']);
        $this->assertSame(
            ['Due on the company date', 'Company-day morning'],
            array_column($data['today']['items'], 'title'),
        );
        $this->assertSame('2026-09-24T15:00:00+00:00', $data['today']['items'][0]['starts_at']);
        $this->assertSame(['open_count' => 2, 'overdue_count' => 1, 'due_today_count' => 1], $data['tasks']);
    }

    // --- Ordering, tie-breaks, limit ---------------------------------------

    public function test_items_are_ordered_by_start_then_all_day_then_source_then_byte_wise_title(): void
    {
        $this->entry('2026-09-24 09:00:00', '2026-09-24 10:00:00', ['title' => 'Timed morning']);
        $this->entry('2026-09-23 22:00:00', '2026-09-24 01:00:00', ['title' => 'Overnight']);
        $this->entry('2026-09-24 00:00:00', '2026-09-24 23:59:59', ['title' => 'zz all-day entry', 'is_all_day' => true]);
        $this->entry('2026-09-24 00:00:00', '2026-09-24 00:30:00', ['title' => 'aa timed at midnight']);
        $this->task('2026-09-24', ['title' => 'alpha']);
        $this->task('2026-09-24', ['title' => 'Beta']);

        $this->assertSame([
            'Overnight',            // earliest start
            'zz all-day entry',     // 00:00, all-day, schedule entry
            'Beta',                 // 00:00, all-day, task — "B" (0x42) sorts before "a" (0x61)
            'alpha',
            'aa timed at midnight', // 00:00 but timed: after every all-day item
        ], $this->todayTitles());
    }

    public function test_numeric_looking_titles_compare_as_strings_and_identical_titles_fall_back_to_public_id(): void
    {
        $this->task('2026-09-24', ['title' => '9']);
        $this->task('2026-09-24', ['title' => '10']);
        $first = $this->task('2026-09-24', ['title' => 'Same']);
        $second = $this->task('2026-09-24', ['title' => 'Same']);

        $items = $this->today()['items'];

        $this->assertSame(['10', '9', 'Same', 'Same'], array_column($items, 'title'));
        $expectedSame = [$first->public_id, $second->public_id];
        sort($expectedSame, SORT_STRING);
        $this->assertSame($expectedSame, [$items[2]['public_id'], $items[3]['public_id']]);
    }

    public function test_at_most_five_items_are_returned_with_a_full_total_count(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $i => $letter) {
            $this->entry("2026-09-24 1{$i}:00:00", "2026-09-24 1{$i}:30:00", ['title' => "Entry {$letter}"]);
        }
        foreach (['p', 'q', 'r', 's'] as $letter) {
            $this->task('2026-09-24', ['title' => "Task {$letter}"]);
        }

        $today = $this->today();

        $this->assertSame(8, $today['total_count']);
        $this->assertSame(['Task p', 'Task q', 'Task r', 'Task s', 'Entry A'], array_column($today['items'], 'title'));
    }

    public function test_the_per_source_limit_keeps_an_all_day_entry_ahead_of_timed_entries_sharing_its_start(): void
    {
        foreach (range(1, 6) as $i) {
            $this->entry('2026-09-24 00:00:00', '2026-09-24 00:30:00', ['title' => "Timed {$i}"]);
        }
        $this->entry('2026-09-24 00:00:00', '2026-09-24 23:59:59', ['title' => 'Zulu all-day', 'is_all_day' => true]);

        $today = $this->today();

        $this->assertSame(7, $today['total_count']);
        $this->assertSame(['Zulu all-day', 'Timed 1', 'Timed 2', 'Timed 3', 'Timed 4'], array_column($today['items'], 'title'));
    }

    public function test_the_bounded_per_source_fetch_equals_a_full_sort_of_the_union(): void
    {
        mt_srand(27);
        for ($i = 0; $i < 12; $i++) {
            $hour = str_pad((string) mt_rand(0, 23), 2, '0', STR_PAD_LEFT);
            $allDay = mt_rand(0, 3) === 0;
            $this->entry(
                $allDay ? '2026-09-24 00:00:00' : "2026-09-24 {$hour}:00:00",
                $allDay ? '2026-09-24 23:59:59' : "2026-09-24 {$hour}:45:00",
                ['title' => chr(mt_rand(65, 70)).chr(mt_rand(97, 102)), 'is_all_day' => $allDay],
            );
            $this->task('2026-09-24', ['title' => chr(mt_rand(65, 70)).chr(mt_rand(97, 102))]);
        }

        $expected = collect(ScheduleEntry::all())->map(fn (ScheduleEntry $e) => [
            $e->starts_at->getTimestamp(), $e->is_all_day ? 0 : 1, 0, $e->title, $e->public_id,
        ])->concat(Task::all()->map(fn (Task $t) => [
            Carbon::parse('2026-09-24', 'UTC')->getTimestamp(), 0, 1, $t->title, $t->public_id,
        ]))->sort(function (array $a, array $b) {
            return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2])
                ?: (strcmp($a[3], $b[3]) <=> 0) ?: (strcmp($a[4], $b[4]) <=> 0);
        })->take(5)->pluck(4)->values()->all();

        $today = $this->today();

        $this->assertSame(24, $today['total_count']);
        $this->assertSame($expected, array_column($today['items'], 'public_id'));
    }

    public function test_the_same_data_always_yields_the_same_items(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->task('2026-09-24', ['title' => 'Same']);
            $this->entry('2026-09-24 00:00:00', '2026-09-24 23:59:59', ['title' => 'Same', 'is_all_day' => true]);
        }

        $this->assertSame($this->today(), $this->today());
    }
}
