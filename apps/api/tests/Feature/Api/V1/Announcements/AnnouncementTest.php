<?php

namespace Tests\Feature\Api\V1\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
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

    // --- Creation ---------------------------------------------------------

    public function test_administrator_can_create_a_company_wide_draft_announcement(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'Office Closure',
            'body' => 'The office will be closed on Friday.',
        ]);

        $response->assertCreated()->assertJson([
            'data' => ['title' => 'Office Closure', 'status' => 'draft', 'audience_type' => 'company_wide', 'published_at' => null],
        ])->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('announcements', ['title' => 'Office Closure', 'status' => 'draft']);
    }

    public function test_creating_an_announcement_requires_title_and_body(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/announcements', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_internal_numeric_ids_are_rejected_for_department_targeting(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $this->postJson('/api/v1/announcements', [
            'title' => 'X', 'body' => 'Y',
            'audience_type' => 'scoped',
            'department_ids' => [(string) $department->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('department_ids.0');
    }

    public function test_creator_accountability_is_recorded(): void
    {
        $admin = $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y']);

        $announcement = Announcement::query()->firstOrFail();
        $this->assertSame($admin->id, $announcement->created_by_user_id);
        $response->assertJson(['data' => ['created_by' => null]]);
    }

    public function test_staff_cannot_create_an_announcement(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y'])->assertForbidden();
    }

    // --- Scoped audience validation ----------------------------------------

    public function test_a_scoped_announcement_requires_at_least_one_department_or_team(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/announcements', [
            'title' => 'X', 'body' => 'Y', 'audience_type' => 'scoped',
        ])->assertUnprocessable()->assertJsonValidationErrors('department_ids');
    }

    public function test_a_company_wide_announcement_cannot_include_department_targeting(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $this->postJson('/api/v1/announcements', [
            'title' => 'X', 'body' => 'Y', 'audience_type' => 'company_wide',
            'department_ids' => [$department->public_id],
        ])->assertUnprocessable()->assertJsonValidationErrors('audience_type');
    }

    public function test_administrator_can_create_a_department_and_team_scoped_announcement(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $team = Team::factory()->create();

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'X', 'body' => 'Y', 'audience_type' => 'scoped',
            'department_ids' => [$department->public_id],
            'team_ids' => [$team->public_id],
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'audience_type' => 'scoped',
                'departments' => [['public_id' => $department->public_id, 'name' => $department->name]],
                'teams' => [['public_id' => $team->public_id, 'name' => $team->name]],
            ],
        ]);
    }

    // --- Editing ------------------------------------------------------------

    public function test_administrator_can_edit_a_draft_announcement(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create(['title' => 'Old']);

        $this->putJson("/api/v1/announcements/{$announcement->public_id}", ['title' => 'New'])
            ->assertOk()->assertJson(['data' => ['title' => 'New']]);
    }

    public function test_administrator_can_edit_a_published_announcement(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->published()->create(['title' => 'Old']);

        $this->putJson("/api/v1/announcements/{$announcement->public_id}", ['title' => 'New'])
            ->assertOk()->assertJson(['data' => ['title' => 'New', 'status' => 'published']]);
    }

    public function test_an_archived_announcement_cannot_be_edited(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->archived()->create();

        $this->putJson("/api/v1/announcements/{$announcement->public_id}", ['title' => 'New'])
            ->assertStatus(409);
    }

    public function test_updating_the_audience_requires_audience_type_even_if_only_department_ids_changes(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();
        $department = Department::factory()->create();

        $this->putJson("/api/v1/announcements/{$announcement->public_id}", [
            'department_ids' => [$department->public_id],
        ])->assertUnprocessable()->assertJsonValidationErrors('audience_type');
    }

    public function test_updating_audience_type_to_company_wide_clears_existing_department_targets(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->putJson("/api/v1/announcements/{$announcement->public_id}", ['audience_type' => 'company_wide'])
            ->assertOk()->assertJson(['data' => ['audience_type' => 'company_wide', 'departments' => []]]);
    }

    public function test_a_request_touching_neither_audience_field_leaves_the_existing_audience_untouched(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->putJson("/api/v1/announcements/{$announcement->public_id}", ['title' => 'New Title'])
            ->assertOk()->assertJson(['data' => [
                'title' => 'New Title',
                'departments' => [['public_id' => $department->public_id, 'name' => $department->name]],
            ]]);
    }

    // --- Lifecycle: publish/archive ------------------------------------------

    public function test_administrator_can_publish_a_draft_announcement(): void
    {
        $admin = $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $response = $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish");

        $response->assertOk()->assertJson(['data' => ['status' => 'published']]);
        $response->assertJsonPath('data.published_at', fn ($value) => $value !== null);

        $announcement->refresh();
        $this->assertSame($admin->id, $announcement->published_by_user_id);
        $this->assertNotNull($announcement->published_at);
    }

    public function test_publishing_an_already_published_announcement_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->published()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertStatus(409);
    }

    public function test_publishing_an_archived_announcement_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->archived()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertStatus(409);
    }

    public function test_a_published_announcements_publish_timestamp_is_server_controlled(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish", [
            'published_at' => '2000-01-01T00:00:00Z',
        ])->assertOk();

        $announcement->refresh();
        $this->assertTrue($announcement->published_at->isAfter(now()->subMinute()));
    }

    public function test_administrator_can_archive_a_published_announcement(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->published()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/archive")
            ->assertOk()->assertJson(['data' => ['status' => 'archived']]);
    }

    public function test_archiving_a_draft_announcement_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/archive")->assertStatus(409);
    }

    public function test_archiving_an_already_archived_announcement_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->archived()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/archive")->assertStatus(409);
    }

    public function test_there_is_no_unarchive_or_republish_endpoint(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->archived()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertStatus(409);
    }

    // --- Deletion vs archival -------------------------------------------------

    public function test_a_draft_announcement_can_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $this->deleteJson("/api/v1/announcements/{$announcement->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_a_published_announcement_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->published()->create();

        $this->deleteJson("/api/v1/announcements/{$announcement->public_id}")->assertStatus(409);
        $this->assertDatabaseHas('announcements', ['id' => $announcement->id]);
    }

    public function test_an_archived_announcement_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->archived()->create();

        $this->deleteJson("/api/v1/announcements/{$announcement->public_id}")->assertStatus(409);
    }

    // --- Management listing --------------------------------------------------

    public function test_management_listing_includes_drafts_and_archived(): void
    {
        $this->actingAsAdministrator();
        Announcement::factory()->create();
        Announcement::factory()->published()->create();
        Announcement::factory()->archived()->create();

        $this->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/announcements?status=draft')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_management_resource_exposes_acknowledgements_count(): void
    {
        $admin = $this->actingAsAdministrator();
        $announcement = Announcement::factory()->published()->create();
        AnnouncementAcknowledgement::factory()->create(['announcement_id' => $announcement->id]);

        $this->getJson("/api/v1/announcements/{$announcement->public_id}")
            ->assertOk()->assertJson(['data' => ['acknowledgements_count' => 1]]);
    }

    public function test_viewing_an_announcement_by_its_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $this->getJson("/api/v1/announcements/{$announcement->id}")->assertNotFound();
    }

    // --- Relational integrity -------------------------------------------------

    public function test_a_department_targeted_by_an_announcement_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->deleteJson("/api/v1/departments/{$department->public_id}")->assertStatus(409);
    }

    public function test_a_team_targeted_by_an_announcement_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $team = Team::factory()->create();
        $announcement = Announcement::factory()->scoped()->create();
        $announcement->teams()->attach($team->id);

        $this->deleteJson("/api/v1/teams/{$team->public_id}")->assertStatus(409);
    }

    public function test_a_staff_member_with_an_announcement_acknowledgement_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        AnnouncementAcknowledgement::factory()->create(['staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }
}
