<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Announcement;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 14 Announcements
 * endpoints (CLAUDE.md §18): `announcements.manage` is Administrator-only
 * and gates the entire management surface — there is no companion
 * `announcements.view`. A Manager holds no elevated Announcement authority
 * at all; self-service (`/me/announcements`) needs only a linked Staff
 * record. See docs/phases/V1_PHASE_14_DEFINITION.md.
 */
class AnnouncementsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_access_the_entire_management_surface(): void
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);
        $announcement = Announcement::factory()->create();

        $this->getJson('/api/v1/announcements')->assertOk();
        $this->getJson("/api/v1/announcements/{$announcement->public_id}")->assertOk();
        $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y'])->assertCreated();
    }

    public function test_manager_cannot_access_the_management_surface_at_all(): void
    {
        $user = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $announcement = Announcement::factory()->create();

        $this->getJson('/api/v1/announcements')->assertForbidden();
        $this->getJson("/api/v1/announcements/{$announcement->public_id}")->assertForbidden();
        $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y'])->assertForbidden();
        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertForbidden();
    }

    public function test_staff_cannot_access_the_management_surface_at_all(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/announcements')->assertForbidden();
        $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y'])->assertForbidden();
    }

    public function test_a_no_role_user_cannot_access_the_management_surface(): void
    {
        $user = User::factory()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/announcements')->assertForbidden();
    }

    public function test_a_user_with_no_linked_staff_record_cannot_use_self_service(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/announcements')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $announcement = Announcement::factory()->published()->create();

        $this->getJson('/api/v1/announcements')->assertUnauthorized();
        $this->getJson('/api/v1/me/announcements')->assertUnauthorized();
        $this->getJson("/api/v1/me/announcements/{$announcement->public_id}")->assertUnauthorized();
    }

    public function test_suspended_account_loses_announcement_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/announcements')
            ->assertForbidden();
    }
}
