<?php

namespace Tests\Feature\Api\V1\Home;

use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Department;
use App\Models\Message;
use App\Models\Notification;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\Reporting\OverdueTasks;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Employee Home (Phase 27, DEC-052) — Needs-attention counts (each in
 * parity with its source module's own definition), the Latest
 * Announcements preview, and the constant-query-count guarantee. See
 * docs/phases/V1_PHASE_27_DEFINITION.md §6.4–§6.6 and §10.
 */
class MyHomeCountsTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function home(?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->user);

        return $this->getJson('/api/v1/me/home')->assertOk()->json('data');
    }

    // --- Tasks ----------------------------------------------------------

    public function test_task_counts_follow_the_open_overdue_and_due_today_definitions(): void
    {
        $mine = ['assignee_staff_id' => $this->staff->id];
        Task::factory()->create([...$mine, 'due_date' => null]);                    // open
        Task::factory()->blocked()->create([...$mine, 'due_date' => '2026-09-20']); // open, overdue
        Task::factory()->inProgress()->create([...$mine, 'due_date' => '2026-09-23']); // open, overdue
        Task::factory()->create([...$mine, 'due_date' => '2026-09-24']);            // open, due today
        Task::factory()->create([...$mine, 'due_date' => '2026-09-30']);            // open
        Task::factory()->completed()->create([...$mine, 'due_date' => '2026-09-20']);
        Task::factory()->cancelled()->create([...$mine, 'due_date' => '2026-09-24']);
        Task::factory()->create(['assignee_staff_id' => Staff::factory()->create()->id, 'due_date' => '2026-09-20']);

        $this->assertSame(
            ['open_count' => 5, 'overdue_count' => 2, 'due_today_count' => 1],
            $this->home()['tasks'],
        );
    }

    public function test_overdue_count_is_in_parity_with_the_canonical_overdue_tasks_definition(): void
    {
        foreach (['2026-09-01', '2026-09-23', '2026-09-24', '2026-10-01'] as $due) {
            Task::factory()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => $due]);
            Task::factory()->completed()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => $due]);
            Task::factory()->blocked()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => $due]);
        }

        $canonical = OverdueTasks::scope(Task::query()->where('assignee_staff_id', $this->staff->id))->count();

        $this->assertSame(4, $canonical);
        $this->assertSame($canonical, $this->home()['tasks']['overdue_count']);
    }

    public function test_due_today_count_matches_the_task_items_in_today(): void
    {
        Task::factory()->count(3)->create(['assignee_staff_id' => $this->staff->id, 'due_date' => '2026-09-24']);
        Task::factory()->completed()->create(['assignee_staff_id' => $this->staff->id, 'due_date' => '2026-09-24']);
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $this->staff->id,
            'starts_at' => '2026-09-24 09:00:00',
            'ends_at' => '2026-09-24 10:00:00',
        ]);

        $home = $this->home();

        $taskItems = array_filter($home['today']['items'], fn (array $item) => $item['source_type'] === 'task');
        $this->assertSame(3, $home['tasks']['due_today_count']);
        $this->assertCount($home['tasks']['due_today_count'], $taskItems);
    }

    // --- Messages -----------------------------------------------------------

    private function conversationWith(Staff ...$members): Conversation
    {
        $conversation = Conversation::factory()->group()->create();
        foreach ($members as $member) {
            ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $member->id]);
        }

        return $conversation;
    }

    public function test_unread_messages_are_summed_across_conversations_respecting_read_position(): void
    {
        $other = Staff::factory()->create();

        // Nothing read yet: every message counts.
        $neverRead = $this->conversationWith($this->staff, $other);
        Message::factory()->count(3)->create(['conversation_id' => $neverRead->id, 'sender_staff_id' => $other->id]);

        // Read up to the second of four messages: two count.
        $partlyRead = $this->conversationWith($this->staff, $other);
        $messages = Message::factory()->count(4)->create(['conversation_id' => $partlyRead->id, 'sender_staff_id' => $other->id]);
        ConversationMember::query()
            ->where('conversation_id', $partlyRead->id)->where('staff_id', $this->staff->id)
            ->update(['last_read_message_id' => $messages[1]->id]);

        // Fully read: none count.
        $fullyRead = $this->conversationWith($this->staff, $other);
        $last = Message::factory()->count(2)->create(['conversation_id' => $fullyRead->id, 'sender_staff_id' => $other->id])->last();
        ConversationMember::query()
            ->where('conversation_id', $fullyRead->id)->where('staff_id', $this->staff->id)
            ->update(['last_read_message_id' => $last->id]);

        // Not a member: never counts.
        $notMine = $this->conversationWith($other, Staff::factory()->create());
        Message::factory()->count(5)->create(['conversation_id' => $notMine->id, 'sender_staff_id' => $other->id]);

        $this->assertSame(5, $this->home()['messages']['unread_count']);
    }

    public function test_the_employees_own_sent_message_is_not_unread(): void
    {
        $other = Staff::factory()->create();
        $conversation = $this->conversationWith($this->staff, $other);
        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'Hello'])->assertCreated();

        $this->assertSame(0, $this->home()['messages']['unread_count']);
    }

    public function test_unread_messages_are_in_parity_with_conversation_member_unread_count(): void
    {
        $other = Staff::factory()->create();
        for ($i = 0; $i < 4; $i++) {
            $conversation = $this->conversationWith($this->staff, $other);
            $messages = Message::factory()->count($i + 2)->create(['conversation_id' => $conversation->id, 'sender_staff_id' => $other->id]);
            if ($i % 2 === 1) {
                ConversationMember::query()
                    ->where('conversation_id', $conversation->id)->where('staff_id', $this->staff->id)
                    ->update(['last_read_message_id' => $messages[$i]->id]);
            }
        }

        $parity = ConversationMember::query()->where('staff_id', $this->staff->id)->get()
            ->sum(fn (ConversationMember $member) => $member->unreadCount());

        $this->assertGreaterThan(0, $parity);
        $this->assertSame($parity, $this->home()['messages']['unread_count']);
    }

    // --- Notifications ------------------------------------------------------

    public function test_unread_notifications_are_in_parity_with_the_unread_count_endpoint(): void
    {
        Notification::factory()->forRecipient($this->user)->count(3)->create();
        Notification::factory()->forRecipient($this->user)->read()->count(2)->create();
        Notification::factory()->forRecipient(User::factory()->create())->count(4)->create();
        Sanctum::actingAs($this->user);

        $endpoint = $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->json('data.unread_count');

        $this->assertSame(3, $endpoint);
        $this->assertSame($endpoint, $this->home()['notifications']['unread_count']);
    }

    // --- Announcements ------------------------------------------------------

    public function test_only_published_audience_eligible_announcements_appear(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);
        $otherDepartment = Department::factory()->create();
        $otherTeam = Team::factory()->create(['department_id' => $otherDepartment->id]);
        $this->staff->update(['department_id' => $department->id, 'team_id' => $team->id]);

        $companyWide = Announcement::factory()->published()->create(['published_at' => now()->subDays(3)]);
        $ownDepartment = Announcement::factory()->published()->scoped()->create(['published_at' => now()->subDays(2)]);
        $ownDepartment->departments()->attach($department->id);
        $ownTeam = Announcement::factory()->published()->scoped()->create(['published_at' => now()->subDay()]);
        $ownTeam->teams()->attach($team->id);

        $elsewhere = Announcement::factory()->published()->scoped()->create(['published_at' => now()]);
        $elsewhere->departments()->attach($otherDepartment->id);
        $elsewhere->teams()->attach($otherTeam->id);
        Announcement::factory()->create(); // draft
        Announcement::factory()->archived()->create();

        $this->assertSame(
            [$ownTeam->public_id, $ownDepartment->public_id, $companyWide->public_id],
            array_column($this->home()['announcements']['latest'], 'public_id'),
        );
    }

    public function test_at_most_three_newest_announcements_are_returned_with_preview_fields_only(): void
    {
        foreach (range(1, 5) as $daysAgo) {
            Announcement::factory()->published()->create([
                'title' => "Announcement {$daysAgo}",
                'published_at' => Carbon::parse('2026-09-24 08:00:00', 'UTC')->subDays($daysAgo),
            ]);
        }

        $latest = $this->home()['announcements']['latest'];

        $this->assertCount(3, $latest);
        $this->assertSame(['Announcement 1', 'Announcement 2', 'Announcement 3'], array_column($latest, 'title'));
        $this->assertSame(['public_id', 'title', 'published_at'], array_keys($latest[0]));
        $this->assertSame('2026-09-23T08:00:00+00:00', $latest[0]['published_at']);
    }

    public function test_announcements_published_at_the_same_instant_are_ordered_newest_row_first(): void
    {
        $sameInstant = Carbon::parse('2026-09-23 12:00:00', 'UTC');
        $first = Announcement::factory()->published()->create(['published_at' => $sameInstant]);
        $second = Announcement::factory()->published()->create(['published_at' => $sameInstant]);
        $third = Announcement::factory()->published()->create(['published_at' => $sameInstant]);
        Announcement::factory()->published()->create(['published_at' => $sameInstant->copy()->subMinute()]);

        $this->assertSame(
            [$third->public_id, $second->public_id, $first->public_id],
            array_column($this->home()['announcements']['latest'], 'public_id'),
        );
    }

    public function test_home_announcements_are_always_a_subset_of_the_existing_self_service_feed(): void
    {
        $department = Department::factory()->create();
        $this->staff->update(['department_id' => $department->id]);
        Announcement::factory()->published()->count(2)->create();
        Announcement::factory()->published()->scoped()->create()->departments()->attach($department->id);
        Announcement::factory()->published()->scoped()->create()->departments()->attach(Department::factory()->create()->id);
        Sanctum::actingAs($this->user);

        $feed = array_column($this->getJson('/api/v1/me/announcements')->assertOk()->json('data'), 'public_id');
        $home = array_column($this->home()['announcements']['latest'], 'public_id');

        $this->assertCount(3, $home);
        $this->assertSame([], array_diff($home, $feed));
    }

    public function test_an_administrator_gets_no_extra_announcements(): void
    {
        $admin = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $admin->id]);
        Announcement::factory()->published()->scoped()->create()->departments()->attach(Department::factory()->create()->id);
        Announcement::factory()->create();

        $this->assertSame([], $this->home($admin)['announcements']['latest']);
    }

    // --- Query count ----------------------------------------------------------

    private function seedDataFor(Staff $staff, int $n): void
    {
        $other = Staff::factory()->create();
        for ($i = 0; $i < $n; $i++) {
            $conversation = $this->conversationWith($staff, $other);
            Message::factory()->count(2)->create(['conversation_id' => $conversation->id, 'sender_staff_id' => $other->id]);
            Task::factory()->create(['assignee_staff_id' => $staff->id, 'due_date' => '2026-09-24']);
            Task::factory()->create(['assignee_staff_id' => $staff->id, 'due_date' => '2026-09-01']);
            ScheduleEntry::factory()->create([
                'creator_staff_id' => $staff->id,
                'starts_at' => '2026-09-24 09:00:00',
                'ends_at' => '2026-09-24 10:00:00',
            ])->participants()->attach($other->id);
            Announcement::factory()->published()->create();
            Notification::factory()->forRecipient($staff->user)->create();
        }
    }

    private function queryCountFor(User $user): int
    {
        Sanctum::actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/me/home')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_query_count_does_not_grow_with_the_amount_of_data(): void
    {
        $this->seedDataFor($this->staff, 1);
        $small = $this->queryCountFor($this->user);

        $busyUser = User::factory()->staff()->create();
        $busyStaff = Staff::factory()->create(['user_id' => $busyUser->id]);
        $this->seedDataFor($busyStaff, 20);
        $large = $this->queryCountFor($busyUser);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(15, $large);
    }
}
