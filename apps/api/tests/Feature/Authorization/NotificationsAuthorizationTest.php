<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 15 Notifications
 * endpoints (CLAUDE.md §18): no permission is defined or required — every
 * `/me/notifications/...` route only needs `auth:sanctum` +
 * `account.active`, and ownership (not role) gates a specific
 * notification. See docs/phases/V1_PHASE_15_DEFINITION.md.
 */
class NotificationsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/me/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/v1/me/notifications/read-all')->assertUnauthorized();
    }

    public function test_suspended_account_loses_notification_access_mid_session(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/notifications')
            ->assertForbidden();
    }

    public function test_every_role_can_use_its_own_notification_inbox(): void
    {
        foreach (['administrator', 'manager', 'staff'] as $role) {
            $user = User::factory()->{$role}()->create();
            Notification::factory()->forRecipient($user)->create();
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public function test_a_no_role_user_can_still_use_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->forRecipient($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_foreign_notification_is_404_not_403(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $notification = Notification::factory()->forRecipient($owner)->create();
        Sanctum::actingAs($requester);

        $this->getJson("/api/v1/me/notifications/{$notification->public_id}")->assertNotFound();
    }
}
