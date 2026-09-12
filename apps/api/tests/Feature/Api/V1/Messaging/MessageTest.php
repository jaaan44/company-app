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
 * Sending and listing messages (Phase 16). Plain text only, immutable
 * after sending — no editing, no soft/hard deletion, no mutation
 * endpoint of any kind. Membership-only authorization.
 */
class MessageTest extends TestCase
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

    public function test_a_member_can_send_a_message(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'Hello there']);

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Hello there');
        $response->assertJsonPath('data.sender.public_id', $a->public_id);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_non_member_cannot_send_a_message(): void
    {
        [$conversation] = $this->directConversation();
        $stranger = $this->staffWithUser();
        Sanctum::actingAs($stranger->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'Hi'])
            ->assertNotFound();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_message_body_is_required(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", [])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_message_body_cannot_exceed_the_maximum_length(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => str_repeat('a', 4001)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_message_body_at_the_maximum_length_is_accepted(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => str_repeat('a', 4000)])
            ->assertCreated();
    }

    public function test_message_body_cannot_be_whitespace_only(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => "   \n  "])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_messages_are_listed_newest_first(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'first'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'second'])->assertCreated();

        $response = $this->getJson("/api/v1/conversations/{$conversation->public_id}/messages")->assertOk();

        $response->assertJsonPath('data.0.body', 'second');
        $response->assertJsonPath('data.1.body', 'first');
    }

    public function test_message_listing_is_paginated(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        foreach (range(1, 3) as $i) {
            $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => "message {$i}"]);
        }

        $response = $this->getJson("/api/v1/conversations/{$conversation->public_id}/messages?per_page=2")->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_a_non_member_cannot_list_messages(): void
    {
        [$conversation, $a] = $this->directConversation();
        $stranger = $this->staffWithUser();
        Sanctum::actingAs($a->user);
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'secret'])->assertCreated();

        Sanctum::actingAs($stranger->user);
        $this->getJson("/api/v1/conversations/{$conversation->public_id}/messages")->assertNotFound();
    }

    public function test_there_is_no_message_edit_endpoint(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $message = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'original'])
            ->assertCreated()->json('data.public_id');

        $this->putJson("/api/v1/conversations/{$conversation->public_id}/messages/{$message}", ['body' => 'edited'])
            ->assertStatus(404);

        $this->assertDatabaseHas('messages', ['public_id' => $message, 'body' => 'original']);
    }

    public function test_there_is_no_message_delete_endpoint(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);
        $message = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'keep me'])
            ->assertCreated()->json('data.public_id');

        $this->deleteJson("/api/v1/conversations/{$conversation->public_id}/messages/{$message}")
            ->assertStatus(404);

        $this->assertDatabaseHas('messages', ['public_id' => $message]);
    }

    public function test_the_message_resource_shape_has_no_internal_id(): void
    {
        [$conversation, $a] = $this->directConversation();
        Sanctum::actingAs($a->user);

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'hi'])
            ->assertCreated();

        $response->assertJsonMissingPath('data.id');
        $response->assertJsonMissingPath('data.conversation_id');
        $response->assertJsonMissingPath('data.sender_staff_id');
        $response->assertJsonStructure(['data' => ['public_id', 'body', 'sender' => ['public_id', 'name'], 'created_at']]);
    }
}
