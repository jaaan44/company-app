<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Enums\ConversationType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Direct (1:1) conversations (Phase 16) — there must be exactly one
 * canonical conversation per unique Staff pair, and it is always
 * exactly two participants, fixed at creation.
 */
class DirectConversationTest extends TestCase
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

    public function test_opening_a_direct_conversation_creates_it(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        Sanctum::actingAs($a->user);

        $response = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $b->public_id]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', ConversationType::Direct->value);
        $response->assertJsonCount(2, 'data.members');
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_members', 2);
    }

    public function test_opening_the_same_pair_again_returns_the_existing_conversation(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        Sanctum::actingAs($a->user);

        $first = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $b->public_id])->assertCreated();
        $second = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $b->public_id])->assertOk();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_opening_a_direct_conversation_in_reverse_returns_the_same_conversation(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();

        Sanctum::actingAs($a->user);
        $first = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $b->public_id])->assertCreated();

        Sanctum::actingAs($b->user);
        $second = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $a->public_id])->assertOk();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_cannot_open_a_direct_conversation_with_self(): void
    {
        $a = $this->staffWithUser();
        Sanctum::actingAs($a->user);

        $this->postJson('/api/v1/conversations/direct', ['staff_id' => $a->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_a_non_participant_cannot_view_a_direct_conversation(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        $stranger = $this->staffWithUser();

        Sanctum::actingAs($a->user);
        $conversation = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $b->public_id])
            ->assertCreated()->json('data.public_id');

        Sanctum::actingAs($stranger->user);
        $this->getJson("/api/v1/conversations/{$conversation}")->assertNotFound();
    }

    public function test_direct_conversation_membership_is_fixed_and_cannot_be_changed(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        $third = $this->staffWithUser();

        Sanctum::actingAs($a->user);
        $conversation = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $b->public_id])
            ->assertCreated()->json('data.public_id');

        $this->postJson("/api/v1/conversations/{$conversation}/members", ['staff_id' => $third->public_id])
            ->assertStatus(409);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$b->public_id}")
            ->assertStatus(409);
    }

    public function test_a_direct_conversation_may_target_a_staff_member_with_no_linked_user(): void
    {
        $a = $this->staffWithUser();
        $noLoginStaff = Staff::factory()->create(['user_id' => null]);
        Sanctum::actingAs($a->user);

        $this->postJson('/api/v1/conversations/direct', ['staff_id' => $noLoginStaff->public_id])
            ->assertCreated();

        $this->assertDatabaseCount('conversation_members', 2);
    }

    public function test_target_staff_id_must_exist(): void
    {
        $a = $this->staffWithUser();
        Sanctum::actingAs($a->user);

        $this->postJson('/api/v1/conversations/direct', ['staff_id' => 'does-not-exist'])
            ->assertUnprocessable();
    }
}
