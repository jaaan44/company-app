<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/v1/conversations/unread-count` (Phase 37B, DEC-068): the
 * requester's unread messages across their conversations, the same count
 * as Home's `messages.unread_count`. Like every Messaging endpoint it
 * needs a linked staff record.
 */
class UnreadCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_it_counts_unread_messages_in_my_conversations_only(): void
    {
        $user = User::factory()->staff()->create();
        $me = Staff::factory()->create(['user_id' => $user->id]);
        $other = Staff::factory()->create();

        $mine = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $mine->id, 'staff_id' => $me->id]);
        ConversationMember::factory()->create(['conversation_id' => $mine->id, 'staff_id' => $other->id]);
        $messages = Message::factory()->count(3)->create(['conversation_id' => $mine->id, 'sender_staff_id' => $other->id]);
        ConversationMember::query()->where('conversation_id', $mine->id)->where('staff_id', $me->id)
            ->update(['last_read_message_id' => $messages[0]->id]);

        $notMine = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $notMine->id, 'staff_id' => $other->id]);
        Message::factory()->count(4)->create(['conversation_id' => $notMine->id, 'sender_staff_id' => $other->id]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/conversations/unread-count')->assertOk()->assertExactJson(['data' => ['unread_count' => 2]]);
        $this->assertSame(2, $this->getJson('/api/v1/me/home')->json('data.messages.unread_count'));
    }

    public function test_it_needs_sign_in_and_a_staff_record(): void
    {
        $this->getJson('/api/v1/conversations/unread-count')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->getJson('/api/v1/conversations/unread-count')->assertForbidden();
    }
}
