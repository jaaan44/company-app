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
 * Ad-hoc, named group conversations (Phase 16). The creator becomes the
 * group's single owner; no co-owners, moderator roles, or ownership
 * transfer exist. Only the owner may add/remove other members; any
 * member may leave themselves, subject to the owner invariant: the
 * owner can never leave/be removed while other members remain.
 */
class GroupConversationTest extends TestCase
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

    private function createGroup(Staff $owner, array $members): string
    {
        Sanctum::actingAs($owner->user);

        return $this->postJson('/api/v1/conversations/group', [
            'name' => 'Ops Team',
            'member_staff_ids' => collect($members)->pluck('public_id')->all(),
        ])->assertCreated()->json('data.public_id');
    }

    public function test_creating_a_group_makes_the_creator_its_owner(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        Sanctum::actingAs($owner->user);

        $response = $this->postJson('/api/v1/conversations/group', [
            'name' => 'Ops Team',
            'member_staff_ids' => [$member->public_id],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', ConversationType::Group->value);
        $response->assertJsonPath('data.name', 'Ops Team');
        $response->assertJsonPath('data.owner.public_id', $owner->public_id);
        $response->assertJsonCount(2, 'data.members');
    }

    public function test_owner_is_deduplicated_if_listed_among_members(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        Sanctum::actingAs($owner->user);

        $response = $this->postJson('/api/v1/conversations/group', [
            'name' => 'Ops Team',
            'member_staff_ids' => [$member->public_id, $owner->public_id],
        ])->assertCreated();

        $response->assertJsonCount(2, 'data.members');
    }

    public function test_group_requires_at_least_one_member(): void
    {
        $owner = $this->staffWithUser();
        Sanctum::actingAs($owner->user);

        $this->postJson('/api/v1/conversations/group', ['name' => 'Solo', 'member_staff_ids' => []])
            ->assertUnprocessable();
    }

    public function test_owner_can_add_a_member(): void
    {
        $owner = $this->staffWithUser();
        $initial = $this->staffWithUser();
        $newMember = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$initial]);

        Sanctum::actingAs($owner->user);
        $this->postJson("/api/v1/conversations/{$conversation}/members", ['staff_id' => $newMember->public_id])
            ->assertCreated();

        $this->assertDatabaseCount('conversation_members', 3);
    }

    public function test_a_non_owner_cannot_add_a_member(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        $newMember = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$member]);

        Sanctum::actingAs($member->user);
        $this->postJson("/api/v1/conversations/{$conversation}/members", ['staff_id' => $newMember->public_id])
            ->assertForbidden();
    }

    public function test_owner_can_remove_a_member(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$member]);

        Sanctum::actingAs($owner->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$member->public_id}")
            ->assertNoContent();

        $this->assertDatabaseCount('conversation_members', 1);
    }

    public function test_a_non_owner_cannot_remove_another_member(): void
    {
        $owner = $this->staffWithUser();
        $memberA = $this->staffWithUser();
        $memberB = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$memberA, $memberB]);

        Sanctum::actingAs($memberA->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$memberB->public_id}")
            ->assertForbidden();
    }

    public function test_a_member_can_leave_the_group_themselves(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$member]);

        Sanctum::actingAs($member->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$member->public_id}")
            ->assertNoContent();

        $this->assertDatabaseCount('conversation_members', 1);
    }

    public function test_owner_cannot_leave_while_other_members_remain(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$member]);

        Sanctum::actingAs($owner->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$owner->public_id}")
            ->assertStatus(409);

        $this->assertDatabaseCount('conversation_members', 2);
    }

    public function test_owner_cannot_be_removed_by_themselves_via_owner_action_while_others_remain_even_as_only_route(): void
    {
        // The owner-removes-another-member path and the owner-leaves path
        // are the same endpoint; explicitly confirm the invariant holds
        // when the owner is the acting party in both roles at once.
        $owner = $this->staffWithUser();
        $memberA = $this->staffWithUser();
        $memberB = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$memberA, $memberB]);

        Sanctum::actingAs($owner->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$owner->public_id}")
            ->assertStatus(409);
    }

    public function test_owner_can_leave_once_they_are_the_last_remaining_member(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$member]);

        Sanctum::actingAs($member->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$member->public_id}")->assertNoContent();

        Sanctum::actingAs($owner->user);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$owner->public_id}")
            ->assertNoContent();

        $this->assertDatabaseCount('conversation_members', 0);
    }

    public function test_a_stranger_cannot_view_a_group_they_do_not_belong_to(): void
    {
        $owner = $this->staffWithUser();
        $member = $this->staffWithUser();
        $stranger = $this->staffWithUser();
        $conversation = $this->createGroup($owner, [$member]);

        Sanctum::actingAs($stranger->user);
        $this->getJson("/api/v1/conversations/{$conversation}")->assertNotFound();
    }
}
