<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relational-integrity coverage extending StaffController::destroy() and
 * ProjectController::destroy() for Phase 16 (mirrors the established
 * per-module deletion-guard pattern, e.g. AnnouncementTest's own
 * "cannot be deleted while referenced" cases).
 */
class MessagingRelationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_staff_member_with_a_current_conversation_membership_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }

    public function test_a_staff_member_who_owns_a_group_conversation_cannot_be_deleted_even_once_emptied(): void
    {
        $this->actingAsAdministrator();
        $owner = Staff::factory()->create();
        $conversation = Conversation::factory()->group()->create(['owner_staff_id' => $owner->id]);
        // The owner is the sole remaining member (an emptied, abandoned
        // group) — ownership itself, not current membership, still blocks
        // deletion, since it is a permanent historical fact.
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $owner->id]);
        ConversationMember::query()->where('conversation_id', $conversation->id)->delete();

        $this->deleteJson("/api/v1/staff/{$owner->public_id}")->assertStatus(409);
    }

    public function test_a_staff_member_who_sent_a_message_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $conversation = Conversation::factory()->create();
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }

    public function test_a_project_with_a_conversation_cannot_be_deleted(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();

        $this->actingAsAdministrator();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $staff->id]);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/projects/{$project->public_id}/conversation")->assertCreated();

        $this->actingAsAdministrator();
        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertStatus(409);
    }
}
