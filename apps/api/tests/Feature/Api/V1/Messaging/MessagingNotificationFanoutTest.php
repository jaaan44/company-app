<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Notification;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for the Phase 16 Notification fan-out wired into
 * MessageController::store() (NotifiesConversationMembers) — the
 * roadmap's own "notification of new messages" dependency on Phase 15.
 * Unlike Announcement publish, every message fans out independently:
 * multiple messages are never collapsed/deduplicated into one
 * notification per conversation.
 */
class MessagingNotificationFanoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staffWithUser(): Staff
    {
        $user = User::factory()->staff()->create();

        return Staff::factory()->create(['user_id' => $user->id]);
    }

    public function test_sending_a_message_notifies_every_other_member(): void
    {
        $sender = $this->staffWithUser();
        $memberA = $this->staffWithUser();
        $memberB = $this->staffWithUser();

        $conversation = Conversation::factory()->group()->create();
        foreach ([$sender, $memberA, $memberB] as $staff) {
            ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $staff->id]);
        }

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hi team'])
            ->assertCreated();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $memberA->user_id]);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $memberB->user_id]);
    }

    public function test_the_sender_receives_no_notification_for_their_own_message(): void
    {
        $sender = $this->staffWithUser();
        $other = $this->staffWithUser();

        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $other->id]);

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])
            ->assertCreated();

        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $sender->user_id]);
    }

    public function test_multiple_messages_are_not_deduplicated_into_one_notification(): void
    {
        $sender = $this->staffWithUser();
        $recipient = $this->staffWithUser();

        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $recipient->id]);

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'one'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'two'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'three'])->assertCreated();

        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_a_member_with_no_linked_user_receives_no_notification(): void
    {
        $sender = $this->staffWithUser();
        $noLoginStaff = Staff::factory()->create(['user_id' => null]);

        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $noLoginStaff->id]);

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])
            ->assertCreated();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_content_is_generic_and_never_contains_the_message_body(): void
    {
        $sender = $this->staffWithUser();
        $recipient = $this->staffWithUser();

        $conversation = Conversation::factory()->group()->create(['name' => 'Confidential Deal Room']);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $recipient->id]);

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", [
            'body' => 'The acquisition price is confidential and must never leak.',
        ])->assertCreated();

        $notification = Notification::query()->where('recipient_user_id', $recipient->user_id)->firstOrFail();

        $this->assertStringNotContainsString('acquisition price', $notification->title);
        $this->assertStringNotContainsString('acquisition price', $notification->message);
        $this->assertStringNotContainsString('Confidential Deal Room', $notification->title);
        $this->assertStringNotContainsString('Confidential Deal Room', $notification->message);
        $this->assertSame(NotificationType::MessageReceived, $notification->type);
    }

    public function test_notification_source_points_to_the_conversation(): void
    {
        $sender = $this->staffWithUser();
        $recipient = $this->staffWithUser();

        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $recipient->id]);

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])
            ->assertCreated();

        $notification = Notification::query()->where('recipient_user_id', $recipient->user_id)->firstOrFail();

        $this->assertSame(NotificationSourceType::Conversation, $notification->source_type);
        $this->assertSame($conversation->public_id, $notification->source_public_id);
    }

    public function test_recipient_sees_the_notification_via_the_established_notifications_api(): void
    {
        $sender = $this->staffWithUser();
        $recipient = $this->staffWithUser();

        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $recipient->id]);

        Sanctum::actingAs($sender->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])
            ->assertCreated();

        Sanctum::actingAs($recipient->user);
        $this->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', NotificationType::MessageReceived->value)
            ->assertJsonPath('data.0.source.type', NotificationSourceType::Conversation->value)
            ->assertJsonPath('data.0.source.public_id', $conversation->public_id);
    }
}
