<?php

namespace Tests\Feature\Api\V1\Notifications;

use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\Notification;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Self-service Notification coverage (Phase 15). Recipient identity is
 * the User account itself (DEC-038) — unlike every other `/me/...`
 * surface, no linked Staff record is required to use these endpoints.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // --- Real end-to-end smoke test (Announcement publish as the real event) ---

    public function test_full_lifecycle_via_a_real_announcement_publish(): void
    {
        $admin = User::factory()->administrator()->create();
        $employeeUser = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $employeeUser->id]);
        $otherUser = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($admin);
        $announcement = Announcement::factory()->create(['title' => 'Office Closure Notice']);
        $publicId = $announcement->public_id;
        $this->postJson("/api/v1/announcements/{$publicId}/publish")->assertOk();

        // 1/3/4: authenticate recipient, list, confirm unread.
        Sanctum::actingAs($employeeUser);
        $list = $this->getJson('/api/v1/me/notifications')->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.type', NotificationType::AnnouncementPublished->value);
        $list->assertJsonPath('data.0.title', 'Office Closure Notice');
        $list->assertJsonPath('data.0.source.type', NotificationSourceType::Announcement->value);
        $list->assertJsonPath('data.0.source.public_id', $publicId);
        $list->assertJsonPath('data.0.read_at', null);
        $notificationPublicId = $list->json('data.0.public_id');

        $this->getJson('/api/v1/me/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread_count', 1);

        // 5/6: mark read, confirm read_at.
        $readResponse = $this->postJson("/api/v1/me/notifications/{$notificationPublicId}/read")->assertOk();
        $firstReadAt = $readResponse->json('data.read_at');
        $this->assertNotNull($firstReadAt);

        // 7: repeat mark-read, confirm idempotency (original timestamp preserved).
        $secondReadResponse = $this->postJson("/api/v1/me/notifications/{$notificationPublicId}/read")->assertOk();
        $this->assertSame($firstReadAt, $secondReadResponse->json('data.read_at'));

        $this->getJson('/api/v1/me/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread_count', 0);

        // 8: a different user cannot see it — 404, not 403.
        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/me/notifications/{$notificationPublicId}")->assertNotFound();
        $this->postJson("/api/v1/me/notifications/{$notificationPublicId}/read")->assertNotFound();
    }

    // --- Listing -----------------------------------------------------------

    public function test_notifications_are_listed_newest_first(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $oldest = Notification::factory()->forRecipient($user)->create(['created_at' => now()->subDays(2)]);
        $newest = Notification::factory()->forRecipient($user)->create(['created_at' => now()]);
        $middle = Notification::factory()->forRecipient($user)->create(['created_at' => now()->subDay()]);

        $response = $this->getJson('/api/v1/me/notifications')->assertOk();

        $response->assertJsonPath('data.0.public_id', $newest->public_id);
        $response->assertJsonPath('data.1.public_id', $middle->public_id);
        $response->assertJsonPath('data.2.public_id', $oldest->public_id);
    }

    public function test_listing_is_paginated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Notification::factory()->forRecipient($user)->count(3)->create();

        $response = $this->getJson('/api/v1/me/notifications?per_page=2')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_unread_filter_returns_only_unread_notifications(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Notification::factory()->forRecipient($user)->read()->create();
        $unread = Notification::factory()->forRecipient($user)->create();

        $response = $this->getJson('/api/v1/me/notifications?unread=1')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.public_id', $unread->public_id);
    }

    public function test_type_filter_narrows_the_list(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Notification::factory()->forRecipient($user)->create(['type' => NotificationType::AnnouncementPublished]);

        $response = $this->getJson('/api/v1/me/notifications?type=announcement_published')->assertOk();

        $response->assertJsonCount(1, 'data');
    }

    public function test_a_notification_belonging_to_another_user_is_never_listed(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Notification::factory()->forRecipient($otherUser)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- Read state ----------------------------------------------------------

    public function test_a_notification_is_unread_by_default(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->forRecipient($user)->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/me/notifications/{$notification->public_id}")
            ->assertOk()->assertJsonPath('data.read_at', null);
    }

    public function test_marking_read_sets_a_server_controlled_timestamp(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->forRecipient($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/notifications/{$notification->public_id}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($value) => $value !== null);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notification = Notification::factory()->forRecipient($owner)->create();
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/me/notifications/{$notification->public_id}/read")->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    // --- Mark all read ---------------------------------------------------------

    public function test_mark_all_read_affects_only_the_authenticated_users_unread_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Notification::factory()->forRecipient($user)->count(2)->create();
        Notification::factory()->forRecipient($user)->read()->create();
        $otherUnread = Notification::factory()->forRecipient($otherUser)->create();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/me/notifications/read-all')->assertOk();

        $response->assertJsonPath('data.updated_count', 2);
        $this->assertNull($otherUnread->fresh()->read_at);
    }

    public function test_already_read_notifications_keep_their_original_timestamp_after_mark_all(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->forRecipient($user)->read()->create(['read_at' => now()->subDay()]);
        $originalReadAt = $notification->read_at->toIso8601String();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/me/notifications/read-all')->assertOk()->assertJsonPath('data.updated_count', 0);

        $this->assertSame($originalReadAt, $notification->fresh()->read_at->toIso8601String());
    }

    // --- API shape -------------------------------------------------------------

    public function test_the_resource_shape_never_exposes_internal_ids_or_recipient_identity(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->forRecipient($user)->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/me/notifications/{$notification->public_id}")->assertOk();

        $response->assertJsonMissingPath('data.id');
        $response->assertJsonMissingPath('data.recipient_user_id');
        $response->assertJsonStructure(['data' => ['public_id', 'type', 'title', 'message', 'source', 'read_at', 'created_at']]);
    }

    public function test_a_notification_with_no_source_reports_a_null_source(): void
    {
        $user = User::factory()->create();
        Notification::factory()->forRecipient($user)->create(['source_type' => null, 'source_public_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('data.0.source', null);
    }

    // --- Authorization / auth ----------------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/me/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/v1/me/notifications/read-all')->assertUnauthorized();
    }

    public function test_a_user_with_no_linked_staff_record_can_still_use_notifications(): void
    {
        // Deliberately different from every other /me/... surface — see
        // NotificationController's docblock. Recipient identity is the
        // User account itself, so a Staff link is not required here.
        $user = User::factory()->create();
        Notification::factory()->forRecipient($user)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(1, 'data');
    }
}
