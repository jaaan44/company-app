<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for Phase 16 Messaging: no
 * `messages.view`/`messages.manage` permission exists, so Administrator's
 * usual Gate::before override grants no implicit access — visibility is
 * membership-only, and a foreign conversation is 404, not 403, for
 * anyone (Administrator included). See
 * docs/phases/V1_PHASE_16_DEFINITION.md and 05_SECURITY_MODEL.md's
 * Messaging Privacy section.
 */
class MessagingAuthorizationTest extends TestCase
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

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        $this->postJson('/api/v1/conversations/direct', ['staff_id' => 'x'])->assertUnauthorized();
        $this->postJson('/api/v1/conversations/group', ['name' => 'x', 'member_staff_ids' => []])->assertUnauthorized();
    }

    public function test_suspended_account_loses_messaging_access_mid_session(): void
    {
        $staff = $this->staffWithUser();
        $token = $staff->user->createToken('mobile')->plainTextToken;

        $staff->user->status = AccountStatus::Suspended;
        $staff->user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/conversations')
            ->assertForbidden();
    }

    public function test_a_user_with_no_linked_staff_record_cannot_use_messaging(): void
    {
        $user = User::factory()->create();
        $target = $this->staffWithUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/conversations')->assertForbidden();
        // A valid target public_id, so the 403 asserted below is genuinely
        // the "no linked staff record" domain check — not an incidental
        // 422 from Form Request validation running first (which it always
        // does, ahead of any controller code, for a nonexistent staff_id).
        $this->postJson('/api/v1/conversations/direct', ['staff_id' => $target->public_id])->assertForbidden();
    }

    public function test_every_application_role_can_use_messaging_when_linked_to_staff(): void
    {
        foreach (['administrator', 'manager', 'staff'] as $role) {
            $user = User::factory()->{$role}()->create();
            Staff::factory()->create(['user_id' => $user->id]);
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/conversations')->assertOk();
        }
    }

    public function test_administrator_gets_no_implicit_access_to_a_foreign_conversation(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $a->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $b->id]);

        $adminUser = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $adminUser->id]);
        Sanctum::actingAs($adminUser);

        $this->getJson("/api/v1/conversations/{$conversation->public_id}")->assertNotFound();
        $this->getJson("/api/v1/conversations/{$conversation->public_id}/messages")->assertNotFound();
        $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", ['body' => 'x'])->assertNotFound();
    }

    public function test_a_foreign_conversation_is_404_not_403(): void
    {
        $a = $this->staffWithUser();
        $stranger = $this->staffWithUser();
        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $a->id]);

        Sanctum::actingAs($stranger->user);

        $response = $this->getJson("/api/v1/conversations/{$conversation->public_id}");
        $response->assertStatus(404);
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
