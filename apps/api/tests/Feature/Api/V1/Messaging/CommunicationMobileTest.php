<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 32 (R-2): tie-breakers and `meta.company_day` on the
 * communication lists, `last_message` on conversations, and reading a
 * conversation also reading its "New message" notifications.
 */
class CommunicationMobileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        // 07:00 Manila on 15 October.
        $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
    }

    private function staffWithUser(): Staff
    {
        $user = User::factory()->staff()->create();

        return Staff::factory()->create(['user_id' => $user->id]);
    }

    /** @param list<Staff> $members */
    private function conversation(array $members): Conversation
    {
        $conversation = Conversation::factory()->create();
        foreach ($members as $m) {
            ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $m->id]);
        }

        return $conversation;
    }

    private const COMPANY_DAY = ['date' => '2026-10-15', 'timezone' => 'Asia/Manila', 'utc_offset' => '+08:00'];

    private function assertOrderPinned(string $order): void
    {
        $this->assertTrue(
            collect(DB::getQueryLog())->contains(fn (array $q) => str_contains($q['query'], $order)),
            "No query ordered by: {$order}",
        );
    }

    // --- Conversations --------------------------------------------------

    public function test_conversations_carry_the_last_message_and_the_company_day(): void
    {
        $me = $this->staffWithUser();
        $other = $this->staffWithUser();
        $chat = $this->conversation([$me, $other]);
        $empty = $this->conversation([$me, $this->staffWithUser()]);
        Message::factory()->create(['conversation_id' => $chat->id, 'sender_staff_id' => $me->id, 'body' => 'First']);
        Message::factory()->create(['conversation_id' => $chat->id, 'sender_staff_id' => $other->id, 'body' => 'Latest']);
        Sanctum::actingAs($me->user);

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('meta.company_day', self::COMPANY_DAY)
            ->assertJsonPath('data.0.public_id', $chat->public_id)
            ->assertJsonPath('data.0.last_message.body', 'Latest')
            ->assertJsonPath('data.0.last_message.sender.public_id', $other->public_id)
            ->assertJsonPath('data.0.last_message.sender.name', $other->displayName())
            ->assertJsonPath('data.0.last_message.created_at', '2026-10-14T23:00:00+00:00')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.1.public_id', $empty->public_id)
            ->assertJsonPath('data.1.last_message', null)
            ->assertJsonMissingPath('data.0.last_message.id');

        $this->getJson("/api/v1/conversations/{$chat->public_id}")
            ->assertOk()
            ->assertJsonPath('data.last_message.body', 'Latest');
    }

    public function test_the_last_message_does_not_add_a_query_per_conversation(): void
    {
        $me = $this->staffWithUser();
        Sanctum::actingAs($me->user);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/conversations?per_page=50')->assertOk();

            return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "messages"') && ! str_contains($q['query'], 'count('))->count();
        };
        $add = function () use ($me) {
            $other = $this->staffWithUser();
            $c = $this->conversation([$me, $other]);
            Message::factory()->create(['conversation_id' => $c->id, 'sender_staff_id' => $other->id]);
        };

        $add();
        $one = $count();
        $add();
        $add();
        $three = $count();

        $this->assertSame($one, $three);
    }

    public function test_empty_conversations_are_ordered_by_id_in_the_sql(): void
    {
        $me = $this->staffWithUser();
        $this->conversation([$me, $this->staffWithUser()]);
        Sanctum::actingAs($me->user);

        DB::enableQueryLog();
        $this->getJson('/api/v1/conversations')->assertOk();

        $this->assertOrderPinned('order by "messages_max_id" desc, "id" desc');
    }

    public function test_messages_carry_the_company_day(): void
    {
        $me = $this->staffWithUser();
        $chat = $this->conversation([$me, $this->staffWithUser()]);
        Sanctum::actingAs($me->user);

        $this->getJson("/api/v1/conversations/{$chat->public_id}/messages")
            ->assertOk()
            ->assertJsonPath('meta.company_day', self::COMPANY_DAY)
            ->assertJsonPath('meta.total', 0);
    }

    // --- Reading a conversation reads its notifications ------------------

    public function test_reading_a_conversation_reads_only_my_notifications_for_it(): void
    {
        $me = $this->staffWithUser();
        $other = $this->staffWithUser();
        $third = $this->staffWithUser();
        $chat = $this->conversation([$me, $other, $third]);
        $elsewhere = $this->conversation([$me, $other]);

        Sanctum::actingAs($other->user);
        $this->postJson("/api/v1/conversations/{$chat->public_id}/messages", ['body' => 'One'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$chat->public_id}/messages", ['body' => 'Two'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$elsewhere->public_id}/messages", ['body' => 'Other chat'])->assertCreated();
        $announcement = Notification::factory()->create(['recipient_user_id' => $me->user_id]);

        Sanctum::actingAs($me->user);
        $this->postJson("/api/v1/conversations/{$chat->public_id}/read")
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 0, 'notifications_marked_read' => 2]]);

        $unread = fn (Staff $s) => Notification::query()->where('recipient_user_id', $s->user_id)->whereNull('read_at')->count();
        // Mine: the other chat's message and the announcement stay unread.
        $this->assertSame(2, $unread($me));
        $this->assertNull($announcement->fresh()?->read_at);
        // The third member's notifications for this chat are untouched.
        $this->assertSame(2, $unread($third));

        // Idempotent.
        $this->postJson("/api/v1/conversations/{$chat->public_id}/read")
            ->assertOk()
            ->assertJsonPath('data.notifications_marked_read', 0);
        $this->getJson('/api/v1/me/notifications/unread-count')->assertJsonPath('data.unread_count', 2);
    }

    public function test_reading_a_conversation_i_am_not_in_marks_nothing(): void
    {
        $me = $this->staffWithUser();
        $other = $this->staffWithUser();
        $chat = $this->conversation([$other, $this->staffWithUser()]);
        Notification::factory()->create([
            'recipient_user_id' => $me->user_id,
            'type' => 'message_received',
            'source_type' => 'conversation',
            'source_public_id' => $chat->public_id,
        ]);
        Sanctum::actingAs($me->user);

        $this->postJson("/api/v1/conversations/{$chat->public_id}/read")->assertNotFound();
        $this->assertSame(1, Notification::query()->whereNull('read_at')->count());
    }

    // --- Notifications and announcements --------------------------------

    public function test_notifications_carry_the_company_day_and_an_id_tie_breaker(): void
    {
        $user = User::factory()->staff()->create();
        $first = Notification::factory()->create(['recipient_user_id' => $user->id]);
        $second = Notification::factory()->create(['recipient_user_id' => $user->id]);
        $third = Notification::factory()->create(['recipient_user_id' => $user->id]);
        Sanctum::actingAs($user);

        DB::enableQueryLog();
        $page1 = $this->getJson('/api/v1/me/notifications?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.company_day', self::COMPANY_DAY);
        $page2 = $this->getJson('/api/v1/me/notifications?per_page=2&page=2')->assertOk();

        $this->assertSame(
            [$third->public_id, $second->public_id, $first->public_id],
            [...$page1->json('data.*.public_id'), ...$page2->json('data.*.public_id')],
        );
        $this->assertOrderPinned('order by "created_at" desc, "id" desc');
    }

    public function test_announcements_carry_the_company_day_and_an_id_tie_breaker(): void
    {
        $me = $this->staffWithUser();
        $a = Announcement::factory()->published()->create();
        $b = Announcement::factory()->published()->create();
        Sanctum::actingAs($me->user);

        DB::enableQueryLog();
        $this->getJson('/api/v1/me/announcements')
            ->assertOk()
            ->assertJsonPath('meta.company_day', self::COMPANY_DAY)
            ->assertJsonPath('data.*.public_id', [$b->public_id, $a->public_id]);

        $this->assertOrderPinned('order by "published_at" desc, "id" desc');
    }
}
