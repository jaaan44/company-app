<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Read/unread state (Phase 16) — a simple per-member last-read-message
 * reference, no visible per-message read receipts. Unread count is
 * always derived from it, never a stored/cached count.
 */
class ReadStateTest extends TestCase
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

    /**
     * @return array{0: Conversation, 1: Staff, 2: Staff}
     */
    private function directConversation(): array
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();

        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $a->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $b->id]);

        return [$conversation, $a, $b];
    }

    public function test_a_new_conversation_has_no_unread_messages(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $this->getJson("/api/v1/conversations/{$conversation->public_id}")
            ->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_a_message_from_another_member_is_unread_for_everyone_else(): void
    {
        [$conversation, $a, $b] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])->assertCreated();

        Sanctum::actingAs($b->user);
        $this->getJson("/api/v1/conversations/{$conversation->public_id}")
            ->assertOk()->assertJsonPath('data.unread_count', 1);
    }

    public function test_sending_a_message_marks_it_read_for_the_sender_only(): void
    {
        [$conversation, $a, $b] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])->assertCreated();

        Sanctum::actingAs($a->user);
        $this->getJson("/api/v1/conversations/{$conversation->public_id}")
            ->assertOk()->assertJsonPath('data.unread_count', 0);

        Sanctum::actingAs($b->user);
        $this->getJson("/api/v1/conversations/{$conversation->public_id}")
            ->assertOk()->assertJsonPath('data.unread_count', 1);
    }

    public function test_marking_a_conversation_read_zeroes_the_unread_count(): void
    {
        [$conversation, $a, $b] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])->assertCreated();

        Sanctum::actingAs($b->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/read")
            ->assertOk()->assertJsonPath('data.unread_count', 0);

        $this->getJson("/api/v1/conversations/{$conversation->public_id}")
            ->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_marking_read_again_with_no_new_messages_is_idempotent(): void
    {
        [$conversation, $a, $b] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])->assertCreated();

        Sanctum::actingAs($b->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/read")->assertOk();
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/read")
            ->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_unread_count_is_derived_and_reflects_messages_sent_after_marking_read(): void
    {
        [$conversation, $a, $b] = $this->directConversation();

        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'one'])->assertCreated();

        Sanctum::actingAs($b->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/read")->assertOk();

        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'two'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'three'])->assertCreated();

        Sanctum::actingAs($b->user);
        $this->getJson("/api/v1/conversations/{$conversation->public_id}")
            ->assertOk()->assertJsonPath('data.unread_count', 2);
    }

    public function test_a_non_member_cannot_mark_a_conversation_read(): void
    {
        [$conversation] = $this->directConversation();
        $stranger = $this->staffWithUser();
        Sanctum::actingAs($stranger->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/read")->assertNotFound();
    }

    public function test_conversation_list_reports_unread_count_per_conversation(): void
    {
        [$conversation, $a, $b] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hello'])->assertCreated();

        Sanctum::actingAs($b->user);
        $response = $this->getJson('/api/v1/conversations')->assertOk();

        $response->assertJsonPath('data.0.public_id', $conversation->public_id);
        $response->assertJsonPath('data.0.unread_count', 1);
    }
}
